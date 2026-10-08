<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\AgUi\EventSink;
use App\Agent\Llm\LlmClient;
use App\Agent\Llm\LlmUnavailable;
use App\Agent\Tool\ToolContext;
use App\Agent\Tool\Toolbox;
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
    ) {
    }

    public function run(RunInput $input, EventSink $sink): void
    {
        $sink->emit(['type' => 'RUN_STARTED', 'threadId' => $input->threadId, 'runId' => $input->runId]);
        $context = new ToolContext($sink, $input->runId);
        $messages = $this->conversation($input);
        if ($messages === []) {
            $sink->emit(['type' => 'RUN_ERROR', 'message' => 'Aucun message à traiter.']);

            return;
        }
        $now = $this->clock ? ($this->clock)() : new \DateTimeImmutable();
        $system = $this->systemPrompt ? ($this->systemPrompt)($input, $now) : SystemPrompt::build($now);
        $tools = $this->toolbox->schemas();

        try {
            for ($step = 0; $step < self::MAX_STEPS; ++$step) {
                $response = $this->llm->complete($system, $messages, $tools);
                $text = $response->text();
                if ($text !== '') {
                    $this->emitText($sink, $text);
                }
                $messages[] = ['role' => 'assistant', 'content' => $response->content];

                $uses = $response->toolUses();
                if ($uses === []) {
                    $sink->emit(['type' => 'RUN_FINISHED', 'threadId' => $input->threadId, 'runId' => $input->runId]);

                    return;
                }
                $results = [];
                foreach ($uses as $use) {
                    $sink->emit(['type' => 'TOOL_CALL_START', 'toolCallId' => $use['id'], 'toolCallName' => $use['name']]);
                    $sink->emit(['type' => 'TOOL_CALL_ARGS', 'toolCallId' => $use['id'], 'delta' => json_encode((object) $use['input'], JSON_UNESCAPED_UNICODE)]);
                    $result = $this->toolbox->execute($use['name'], $use['input'], $context);
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
            $this->emitText($sink, 'Je m’arrête là : la demande a demandé trop d’étapes. Peux-tu la découper ?');
            $sink->emit(['type' => 'RUN_FINISHED', 'threadId' => $input->threadId, 'runId' => $input->runId]);
        } catch (LlmUnavailable $e) {
            $sink->emit(['type' => 'RUN_ERROR', 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error('agent run failed', ['exception' => $e, 'runId' => $input->runId]);
            $sink->emit(['type' => 'RUN_ERROR', 'message' => 'L’agent a rencontré une erreur.']);
        }
    }

    /**
     * Historique au format du modèle : rôles alternés, en commençant par l'utilisateur ;
     * l'action d'interface (ou les erreurs de rendu) devient un message utilisateur.
     *
     * @return list<array{role: 'user'|'assistant', content: string|list<array<string, mixed>>}>
     */
    private function conversation(RunInput $input): array
    {
        $turns = $input->messages;
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
        if ($notes !== []) {
            $turns[] = ['role' => 'user', 'content' => mb_substr(implode("\n", $notes), 0, RunInput::MAX_CHARS)];
        }

        $merged = [];
        foreach ($turns as $turn) {
            $last = \count($merged) - 1;
            if ($last >= 0 && $merged[$last]['role'] === $turn['role']) {
                $merged[$last]['content'] .= "\n\n".$turn['content'];
            } else {
                $merged[] = $turn;
            }
        }
        while ($merged !== [] && $merged[0]['role'] !== 'user') {
            array_shift($merged);
        }
        if ($merged !== [] && $merged[\count($merged) - 1]['role'] !== 'user') {
            return []; // rien de nouveau à traiter
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
