<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\AgUi\EventSink;
use App\Agent\Llm\LlmClient;
use App\Agent\Llm\LlmStream;
use App\Agent\Llm\LlmUnavailable;
use App\Agent\Tool\ToolContext;
use App\Agent\Tool\Toolbox;
use App\Agent\Tool\ToolResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * Boucle d'agent pour un run AG-UI : modèle → outils → modèle… jusqu'à une réponse
 * sans appel d'outil (ou la limite d'étapes). Tout ce qui se passe est émis en
 * événements AG-UI : texte, appels d'outils, interfaces A2UI (via render_ui).
 */
final class AgentRunner
{
    public const MAX_STEPS = 8;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly Toolbox $toolbox,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?\Closure $clock = null,
        /** @var (\Closure(RunInput, \DateTimeImmutable): string)|null prompt système (défaut : profil tâches) */
        private readonly ?\Closure $systemPrompt = null,
        private readonly ?ThreadStore $threads = null,
        /** Portée de la mémoire des conversations (utilisateur + profil). */
        private readonly string $threadScope = '',
    ) {
    }

    public function run(RunInput $input, EventSink $sink): void
    {
        $sink->emit(['type' => 'RUN_STARTED', 'threadId' => $input->threadId, 'runId' => $input->runId]);
        $context = new ToolContext($sink, $input->runId, $input->appContext);
        $state = $this->threads?->load($this->threadScope, $input->threadId);
        $messages = $state !== null && $input->messageCount >= $state->messageCount
            ? $this->continueFrom($state, $input)
            : $this->conversation($input);
        if ($messages === []) {
            $sink->emit(['type' => 'RUN_ERROR', 'message' => 'Aucun message à traiter.']);

            return;
        }
        if ($state !== null && $input->messageCount >= $state->messageCount) {
            $context->workspace = $state->workspace;
        }
        $now = $this->clock ? ($this->clock)() : new \DateTimeImmutable();
        $system = $this->systemPrompt ? ($this->systemPrompt)($input, $now) : SystemPrompt::build($now);
        $tools = $this->toolbox->schemas();

        try {
            for ($step = 0; $step < self::MAX_STEPS; ++$step) {
                // Texte et débuts d'appels d'outils relayés pendant la génération (si le client streame).
                $textId = null;
                $started = [];
                $stream = new LlmStream(
                    static function (string $delta) use ($sink, &$textId): void {
                        if ($textId === null) {
                            $textId = Uuid::v4()->toRfc4122();
                            $sink->emit(['type' => 'TEXT_MESSAGE_START', 'messageId' => $textId, 'role' => 'assistant']);
                        }
                        $sink->emit(['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => $textId, 'delta' => $delta]);
                    },
                    static function (string $id, string $name) use ($sink, &$started): void {
                        $started[$id] = true;
                        $sink->emit(['type' => 'TOOL_CALL_START', 'toolCallId' => $id, 'toolCallName' => $name]);
                    },
                );
                $response = $this->llm->complete($system, $messages, $tools, $stream);
                if ($textId !== null) {
                    $sink->emit(['type' => 'TEXT_MESSAGE_END', 'messageId' => $textId]);
                } elseif (($text = $response->text()) !== '') {
                    $this->emitText($sink, $text);
                }
                // Contenu brut si disponible : un tool_use `input: {}` doit rester un objet.
                $messages[] = ['role' => 'assistant', 'content' => $response->rawContent ?? $response->content];

                $uses = $response->toolUses();
                $truncated = $response->stopReason === 'max_tokens';
                if ($uses === []) {
                    if ($truncated) {
                        $this->emitText($sink, '(Réponse coupée : limite de longueur atteinte.)');
                    }
                    $this->remember($input, $messages, $context);
                    $sink->emit(['type' => 'RUN_FINISHED', 'threadId' => $input->threadId, 'runId' => $input->runId]);

                    return;
                }
                $results = [];
                foreach ($uses as $use) {
                    if (!isset($started[$use['id']])) {
                        $sink->emit(['type' => 'TOOL_CALL_START', 'toolCallId' => $use['id'], 'toolCallName' => $use['name']]);
                    }
                    if ($truncated) {
                        // Arguments incomplets : ne pas exécuter, demander au modèle de faire plus court.
                        $result = ToolResult::json(['error' => 'Appel interrompu : ta réponse a atteint la limite de longueur '
                            .'avant la fin des arguments. Rappelle l’outil avec un contenu plus compact '
                            .'(moins de texte, gabarits et listes à gabarit plutôt que des blocs répétés).'], true);
                    } else {
                        $sink->emit(['type' => 'TOOL_CALL_ARGS', 'toolCallId' => $use['id'], 'delta' => json_encode((object) $use['input'], JSON_UNESCAPED_UNICODE)]);
                        $context->rawInput = $use['raw'];
                        $result = $this->toolbox->execute($use['name'], $use['input'], $context);
                        $context->rawInput = null;
                    }
                    $sink->emit(['type' => 'TOOL_CALL_END', 'toolCallId' => $use['id']]);
                    $results[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => $use['id'],
                        'content' => $result->content,
                        'is_error' => $result->isError,
                    ];
                }
                $messages[] = ['role' => 'user', 'content' => $results];
            }
            $this->emitText($sink, 'Je m’arrête là : la demande a demandé trop d’étapes. Dis-moi de continuer, ou découpe-la.');
            $messages[] = ['role' => 'assistant', 'content' => '(Arrêt : limite d’étapes atteinte.)'];
            $this->remember($input, $messages, $context);
            $sink->emit(['type' => 'RUN_FINISHED', 'threadId' => $input->threadId, 'runId' => $input->runId]);
        } catch (LlmUnavailable $e) {
            $error = ['type' => 'RUN_ERROR', 'message' => $e->getMessage()];
            if ($e->errorCode() !== null) {
                $error['code'] = $e->errorCode();
            }
            $sink->emit($error);
        } catch (\Throwable $e) {
            $this->logger->error('agent run failed', ['exception' => $e, 'runId' => $input->runId]);
            $sink->emit(['type' => 'RUN_ERROR', 'message' => 'L’agent a rencontré une erreur.']);
        }
    }

    /**
     * Run suivant d'une conversation connue : la transcription complète du run précédent
     * (appels d'outils compris), puis ce qui est nouveau côté navigateur (message, action).
     *
     * @return list<array{role: string, content: mixed}>
     */
    private function continueFrom(ThreadState $state, RunInput $input): array
    {
        $newCount = $input->messageCount - $state->messageCount;
        $turns = $newCount > 0 ? \array_slice($input->messages, -min($newCount, \count($input->messages))) : [];
        // Les réponses texte du run précédent sont déjà dans la transcription.
        while ($turns !== [] && $turns[0]['role'] === 'assistant') {
            array_shift($turns);
        }
        $new = $this->merge([...$turns, ...$this->notes($input)]);
        if ($new === [] || $new[\count($new) - 1]['role'] !== 'user') {
            return [];
        }

        return [...$state->messages, ...$new];
    }

    /**
     * @param list<array{role: string, content: mixed}> $messages
     */
    private function remember(RunInput $input, array $messages, ToolContext $context): void
    {
        $this->threads?->save($this->threadScope, $input->threadId, new ThreadState($messages, $input->messageCount, $context->workspace));
    }

    /**
     * Historique au format du modèle : rôles alternés, en commençant par l'utilisateur ;
     * l'action d'interface (ou les erreurs de rendu) devient un message utilisateur.
     *
     * @return list<array{role: 'user'|'assistant', content: string|list<array<string, mixed>>}>
     */
    private function conversation(RunInput $input): array
    {
        $merged = $this->merge([...$input->messages, ...$this->notes($input)]);
        while ($merged !== [] && $merged[0]['role'] !== 'user') {
            array_shift($merged);
        }
        if ($merged !== [] && $merged[\count($merged) - 1]['role'] !== 'user') {
            return []; // rien de nouveau à traiter
        }

        return $merged;
    }

    /**
     * L'action d'interface (ou les erreurs de rendu) devient un message utilisateur.
     *
     * @return list<array{role: 'user', content: string}>
     */
    private function notes(RunInput $input): array
    {
        $notes = [];
        if ($input->a2uiAction !== null) {
            $action = \is_array($input->a2uiAction['action'] ?? null) ? $input->a2uiAction['action'] : [];
            $notes[] = sprintf(
                '[action] « %s » sur l’interface %s (composant %s), contexte : %s',
                (string) ($action['name'] ?? '?'),
                (string) ($action['surfaceId'] ?? '?'),
                (string) ($action['sourceComponentId'] ?? '?'),
                json_encode($action['context'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE),
            );
        }
        if ($input->sduiAction !== null) {
            $notes[] = sprintf(
                '[action] « %s », contexte : %s',
                (string) ($input->sduiAction['name'] ?? '?'),
                json_encode($input->sduiAction['context'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE),
            );
        }
        if ($input->a2uiErrors !== []) {
            $notes[] = '[erreurs d’interface] '.json_encode($input->a2uiErrors, JSON_UNESCAPED_UNICODE);
        }

        return $notes === [] ? [] : [['role' => 'user', 'content' => mb_substr(implode("\n", $notes), 0, RunInput::MAX_CHARS)]];
    }

    /**
     * Rôles alternés : deux tours consécutifs du même rôle sont fusionnés.
     *
     * @param list<array{role: string, content: string}> $turns
     *
     * @return list<array{role: string, content: string}>
     */
    private function merge(array $turns): array
    {
        $merged = [];
        foreach ($turns as $turn) {
            $last = \count($merged) - 1;
            if ($last >= 0 && $merged[$last]['role'] === $turn['role']) {
                $merged[$last]['content'] .= "\n\n".$turn['content'];
            } else {
                $merged[] = $turn;
            }
        }

        return $merged;
    }

    private function emitText(EventSink $sink, string $text): void
    {
        $id = Uuid::v4()->toRfc4122();
        $sink->emit(['type' => 'TEXT_MESSAGE_START', 'messageId' => $id, 'role' => 'assistant']);
        $sink->emit(['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => $id, 'delta' => $text]);
        $sink->emit(['type' => 'TEXT_MESSAGE_END', 'messageId' => $id]);
    }
}
