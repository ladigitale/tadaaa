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
    public const MAX_STEPS = 12;
    /** Outils dont le succès termine le tour : l'utilisateur a ce qu'il faut, un appel de plus au modèle serait superflu. */
    public const FINAL_TOOLS = ['render_ui'];
    /** À partir de ce nombre d'étapes restantes, le modèle est prévenu pour conclure. */
    public const WARN_REMAINING = 2;
    /** Libellé d'état envoyé pendant les silences du fournisseur (`CUSTOM status`). */
    public const WAITING_STATUS = 'Le modèle réfléchit…';

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
        $started0 = microtime(true);
        $stats = ['steps' => 0, 'toolCalls' => 0, 'toolErrors' => 0, 'invalidArguments' => 0, 'sequence' => []];
        // Texte ou outil produit pendant le run : un run sans rien de tout ça est un échec du modèle, pas un succès.
        $produced = false;

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
                    // Génération longue ou sans flux : un événement régulier évite que le client croie l'agent mort.
                    static function () use ($sink): void {
                        $sink->emit(['type' => 'CUSTOM', 'name' => 'status', 'value' => self::WAITING_STATUS]);
                    },
                );
                ++$stats['steps'];
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
                $produced = $produced || $textId !== null || $response->text() !== '' || $uses !== [];
                if ($uses === []) {
                    if (!$produced) {
                        // Réponse vide (modèle qui « décroche », filtre du fournisseur…) : le dire, avec un code que le chat sait relancer.
                        $this->logger->warning('agent run: empty model response', ['runId' => $input->runId, 'stopReason' => $response->stopReason] + $stats);
                        $sink->emit(['type' => 'RUN_ERROR', 'code' => 'EMPTY_RESPONSE', 'message' => 'Le modèle n’a rien répondu. Réessaie, ou choisis un autre modèle dans les réglages de l’agent.']);

                        return;
                    }
                    if ($truncated) {
                        $this->emitText($sink, '(Réponse coupée : limite de longueur atteinte.)');
                    }
                    $this->remember($input, $messages, $context);
                    $sink->emit(['type' => 'RUN_FINISHED', 'threadId' => $input->threadId, 'runId' => $input->runId]);
                    $this->logger->info('agent run finished', ['runId' => $input->runId, 'ms' => (int) ((microtime(true) - $started0) * 1000)] + $stats);

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
                    } elseif ($use['invalid']) {
                        // JSON d'arguments mal formé : ne pas exécuter avec `{}` (le modèle croirait avoir agi).
                        ++$stats['invalidArguments'];
                        $result = ToolResult::json(['error' => sprintf(
                            'Arguments de %s invalides : ce n’est pas un objet JSON valide%s. Rappelle l’outil avec des arguments JSON corrects.',
                            $use['name'] !== '' ? $use['name'] : 'l’outil',
                            $use['name'] === '' ? ' (nom d’outil manquant)' : '',
                        )], true);
                    } else {
                        $sink->emit(['type' => 'TOOL_CALL_ARGS', 'toolCallId' => $use['id'], 'delta' => json_encode((object) $use['input'], JSON_UNESCAPED_UNICODE)]);
                        $context->rawInput = $use['raw'];
                        $result = $this->toolbox->execute($use['name'], $use['input'], $context);
                        $context->rawInput = null;
                    }
                    ++$stats['toolCalls'];
                    $stats['sequence'][] = $use['name'].($result->isError ? '!' : '');
                    $stats['toolErrors'] += $result->isError ? 1 : 0;
                    $sink->emit(['type' => 'TOOL_CALL_END', 'toolCallId' => $use['id']]);
                    $results[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => $use['id'],
                        'content' => $result->content,
                        'is_error' => $result->isError,
                    ];
                }
                // Interface affichée avec sa légende : rien à ajouter, on évite un tour de modèle (et une étape).
                $said = $textId !== null || $response->text() !== '';
                if ($said && !$truncated && $this->allFinal($uses, $results)) {
                    $messages[] = ['role' => 'user', 'content' => $results];
                    $messages[] = ['role' => 'assistant', 'content' => '(Interface affichée.)'];
                    $this->remember($input, $messages, $context);
                    $sink->emit(['type' => 'RUN_FINISHED', 'threadId' => $input->threadId, 'runId' => $input->runId]);
                    $this->logger->info('agent run finished', ['runId' => $input->runId, 'early' => true, 'ms' => (int) ((microtime(true) - $started0) * 1000)] + $stats);

                    return;
                }
                $remaining = self::MAX_STEPS - $step - 1;
                if ($remaining > 0 && $remaining <= self::WARN_REMAINING) {
                    // Un modèle qui ignore sa limite la découvre en plein travail : le lui dire avant.
                    $results[] = ['type' => 'text', 'text' => sprintf(
                        '[Il te reste %d étape%s avant la limite : conclus maintenant (sauvegarde ou propose ce qui est prêt, et dis ce qui reste à faire).]',
                        $remaining,
                        $remaining > 1 ? 's' : '',
                    )];
                }
                $messages[] = ['role' => 'user', 'content' => $results];
            }
            $this->logger->warning('agent run: step limit', ['runId' => $input->runId] + $stats);
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
            $notes[] = '[erreurs d’interface] '.json_encode($input->a2uiErrors, JSON_UNESCAPED_UNICODE)
                .' — si elles viennent d’une interface que tu as affichée, corrige-la avec render_ui (même surfaceId) ; sinon ignore-les.';
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

    /**
     * @param list<array{id: string, name: string, input: array<string, mixed>, raw: ?\stdClass, invalid: bool}> $uses
     * @param list<array<string, mixed>>                                                                            $results
     */
    private function allFinal(array $uses, array $results): bool
    {
        foreach ($uses as $i => $use) {
            if (!\in_array($use['name'], self::FINAL_TOOLS, true) || !empty($results[$i]['is_error'])) {
                return false;
            }
        }

        return $uses !== [];
    }

    private function emitText(EventSink $sink, string $text): void
    {
        $id = Uuid::v4()->toRfc4122();
        $sink->emit(['type' => 'TEXT_MESSAGE_START', 'messageId' => $id, 'role' => 'assistant']);
        $sink->emit(['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => $id, 'delta' => $text]);
        $sink->emit(['type' => 'TEXT_MESSAGE_END', 'messageId' => $id]);
    }
}
