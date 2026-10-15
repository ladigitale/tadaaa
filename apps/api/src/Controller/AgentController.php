<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agent\AgentFactory;
use App\Agent\AgentProfile;
use App\Agent\AgUi\RecordingEventSink;
use App\Agent\AgUi\SseEventSink;
use App\Agent\ConversationHistory;
use App\Agent\RunInput;
use App\Entity\User;
use App\Service\UsageMeter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Agent intégré, protocole AG-UI : `POST /api/agent/run` (profil tâches) ou
 * `POST /api/agent/{profile}/run` (ex. `artifacts` : atelier d'Artefacts) avec un
 * RunAgentInput, réponse en flux SSE d'événements AG-UI. Client : `sonic-chat` (agent-stack).
 */
#[Route('/api/agent')]
#[IsGranted('ROLE_USER')]
final class AgentController extends AbstractController
{
    public function __construct(
        private readonly AgentFactory $agents,
        private readonly UsageMeter $usage,
        private readonly ConversationHistory $history,
        #[Autowire(service: 'limiter.agent_runs')]
        private readonly RateLimiterFactoryInterface $agentRunsLimiter,
    ) {
    }

    #[Route('/run', name: 'api_agent_run', methods: ['POST'], defaults: ['profile' => AgentProfile::TASKS])]
    #[Route('/{profile}/run', name: 'api_agent_profile_run', methods: ['POST'], requirements: ['profile' => 'tasks|artifacts'])]
    public function run(Request $request, string $profile): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $limit = $this->agentRunsLimiter->create($user->getUserIdentifier())->consume();
        if (!$limit->isAccepted()) {
            return new JsonResponse(['detail' => 'Trop de demandes à l’agent, réessaie dans quelques minutes.'], 429, [
                'Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()),
            ]);
        }
        try {
            $input = RunInput::fromArray($request->toArray());
        } catch (\Throwable $e) {
            return new JsonResponse(['detail' => $e->getMessage()], 400);
        }
        try {
            $this->usage->increment($user, $user->getActiveDataset(), UsageMeter::AGENT_RUNS);
        } catch (\Throwable) {
            // L'observabilité ne doit pas bloquer l'agent.
        }

        $runner = $this->agents->create($profile, $user, $input->appContext);
        $history = $this->history;
        $response = new StreamedResponse(static function () use ($runner, $input, $history, $user, $profile): void {
            // Un run enchaîne plusieurs appels au modèle (documents longs) : pas de limite PHP,
            // et le run est enregistré même si le navigateur est parti entre-temps.
            set_time_limit(0);
            ignore_user_abort(true);
            $recorder = new RecordingEventSink(new SseEventSink());
            $last = $input->messages !== [] ? $input->messages[\count($input->messages) - 1] : null;
            if ($last !== null && $last['role'] === 'user' && $input->a2uiAction === null && $input->sduiAction === null) {
                $recorder->user($last['content']);
            }
            try {
                $history->rehydrate($user, $profile, $input->threadId);
            } catch (\Throwable) {
                // Historique best-effort : l'agent repartira du texte reçu.
            }
            try {
                $runner->run($input, $recorder);
            } finally {
                try {
                    $history->record($user, $profile, $input, $recorder);
                } catch (\Throwable) {
                    // L'historique ne doit jamais faire échouer un run.
                }
            }
        });
        $response->headers->set('Content-Type', 'text/event-stream; charset=utf-8');
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }
}
