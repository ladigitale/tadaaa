<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agent\Llm\LlmUnavailable;
use App\Agent\Settings\AgentSettingsService;
use App\Entity\AuditLog;
use App\Entity\User;
use App\Service\AuditLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Réglages « Assistant IA » de l'utilisateur connecté. La clé API n'est jamais renvoyée.
 */
#[Route('/api/agent/settings')]
#[IsGranted('ROLE_USER')]
final class AgentSettingsController extends AbstractController
{
    public function __construct(
        private readonly AgentSettingsService $settings,
        private readonly AuditLogger $audit,
        #[Autowire(service: 'limiter.agent_settings_test')]
        private readonly RateLimiterFactoryInterface $testLimiter,
    ) {
    }

    #[Route('', name: 'api_agent_settings_get', methods: ['GET'])]
    public function show(): JsonResponse
    {
        return $this->json($this->settings->view($this->user()));
    }

    #[Route('', name: 'api_agent_settings_put', methods: ['PUT'])]
    public function update(Request $request): JsonResponse
    {
        $user = $this->user();
        try {
            $patch = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['detail' => 'JSON invalide.'], 400);
        }
        $errors = $this->settings->update($user, $patch);
        if ($errors !== []) {
            return $this->json(['detail' => $errors[0]['message'], 'violations' => $errors], 422);
        }
        try {
            $view = $this->settings->view($user);
            $this->audit->log($user, AuditLog::CATEGORY_TOKEN, 'agent.settings_updated', [
                'provider' => $view['provider'],
                'model' => $view['model'],
                'keyChanged' => \array_key_exists('apiKey', $patch),
                'customBaseUrl' => $view['customBaseUrlEnabled'],
            ], $request->getClientIp());
        } catch (\Throwable) {
            // L'observabilité ne doit pas bloquer l'enregistrement.
        }

        return $this->json($this->settings->view($user));
    }

    /** Petit appel au modèle avec les réglages enregistrés. */
    #[Route('/test', name: 'api_agent_settings_test', methods: ['POST'])]
    public function test(): JsonResponse
    {
        $user = $this->user();
        if (!$this->testLimiter->create($user->getUserIdentifier())->consume()->isAccepted()) {
            return $this->json(['ok' => false, 'message' => 'Trop d’essais : réessaie dans quelques minutes.'], 429);
        }
        ['client' => $client, 'source' => $source, 'model' => $model] = $this->settings->clientFor($user);
        try {
            $response = $client->complete(
                'Réponds uniquement « OK ».',
                [['role' => 'user', 'content' => 'Test de connexion.']],
                [],
            );
            $text = mb_substr($response->text(), 0, 80);

            return $this->json([
                'ok' => true,
                'message' => sprintf('Connexion réussie%s.', $text !== '' ? ' — réponse : « '.$text.' »' : ''),
                'source' => $source,
                'model' => $model,
            ]);
        } catch (LlmUnavailable $e) {
            return $this->json(['ok' => false, 'message' => $e->getMessage(), 'source' => $source, 'model' => $model]);
        }
    }

    private function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
