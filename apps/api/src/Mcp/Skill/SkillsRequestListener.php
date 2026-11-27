<?php

declare(strict_types=1);

namespace App\Mcp\Skill;

use App\Service\ArtifactSkills;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Méthodes skills/list et skills/get de l'extension Skills (SEP-2640) sur le point MCP HTTP.
 *
 * Le SDK MCP (mcp/sdk 0.6) refuse toute méthode JSON-RPC absente de sa liste fixe (MessageFactory) avant même
 * d'appeler les gestionnaires enregistrés : ces deux requêtes sont donc servies ici, après le pare-feu
 * (priorité 4 < 8 : l'utilisateur est déjà authentifié par le jeton OAuth du MCP), sans passer par le SDK.
 * Les autres messages (et les lots JSON-RPC) continuent vers le contrôleur MCP.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final class SkillsRequestListener
{
    public const METHODS = ['skills/list', 'skills/get'];

    /** Durée de fraîcheur annoncée (ttlMs) : les skills changent au déploiement seulement. */
    private const TTL_MS = 300000;

    public function __construct(
        private readonly ArtifactSkills $skills,
        /** Chemin du point MCP HTTP (mcp.yaml : http.path). */
        private readonly string $path = '/mcp',
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->getMethod() !== 'POST' || rtrim($request->getPathInfo(), '/') !== rtrim($this->path, '/')) {
            return;
        }
        $raw = (string) $request->getContent();
        if ($raw === '' || $raw[0] !== '{' || !str_contains($raw, '"skills/')) {
            return;
        }
        $message = json_decode($raw, true);
        if (!\is_array($message) || !\in_array($message['method'] ?? null, self::METHODS, true)) {
            return;
        }

        $response = new JsonResponse($this->handle($message));
        $session = $request->headers->get('Mcp-Session-Id');
        if (\is_string($session) && $session !== '') {
            $response->headers->set('Mcp-Session-Id', $session);
        }
        $event->setResponse($response);
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    public function handle(array $message): array
    {
        $id = $message['id'] ?? null;
        $params = \is_array($message['params'] ?? null) ? $message['params'] : [];

        if ($message['method'] === 'skills/list') {
            $entries = array_values(array_map(ArtifactSkills::entry(...), $this->skills->all()));

            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'resultType' => 'complete',
                'skills' => $entries,
                'ttlMs' => self::TTL_MS,
                'cacheScope' => 'public',
            ]];
        }

        $uri = \is_string($params['uri'] ?? null) ? $params['uri'] : '';
        $skill = $uri !== '' ? $this->skills->byUri($uri) : null;
        if ($skill === null) {
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => [
                'code' => -32602,
                'message' => $uri === '' ? 'params.uri requis (URI du SKILL.md).' : sprintf('Skill inconnue : %s', $uri),
            ]];
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
            'resultType' => 'complete',
            'skill' => ArtifactSkills::entry($skill),
            'ttlMs' => self::TTL_MS,
            'cacheScope' => 'public',
        ]];
    }
}
