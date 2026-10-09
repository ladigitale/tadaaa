<?php

declare(strict_types=1);

namespace App\Agent;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Mémoire d'une conversation de l'agent, côté serveur, entre deux runs.
 *
 * Le navigateur ne renvoie que les textes échangés ; sans cette mémoire l'agent perdait
 * à chaque message ses appels d'outils (catalogue lu, document composé, aperçu) et
 * repartait de zéro : « Publier » relançait toute la construction. On garde donc la
 * transcription complète (blocs tool_use / tool_result) et l'état de l'atelier, par
 * utilisateur, profil et threadId, quelques heures.
 */
final class ThreadStore
{
    public const TTL = 4 * 3600;

    /** Au-delà, la transcription est allégée (résultats d'outils anciens raccourcis). */
    public const MAX_BYTES = 1_500_000;

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function load(string $scope, string $threadId): ?ThreadState
    {
        try {
            $item = $this->cache->getItem($this->key($scope, $threadId));
        } catch (\Throwable) {
            return null;
        }
        if (!$item->isHit() || !\is_string($item->get())) {
            return null;
        }
        $state = @unserialize($item->get(), ['allowed_classes' => [ThreadState::class, \stdClass::class]]);

        return $state instanceof ThreadState ? $state : null;
    }

    public function save(string $scope, string $threadId, ThreadState $state): void
    {
        $data = serialize($state);
        if (\strlen($data) > self::MAX_BYTES) {
            $state = $state->compacted();
            $data = serialize($state);
            if (\strlen($data) > self::MAX_BYTES) {
                return;
            }
        }
        try {
            $item = $this->cache->getItem($this->key($scope, $threadId));
            $item->set($data);
            $item->expiresAfter(self::TTL);
            $this->cache->save($item);
        } catch (\Throwable) {
            // Mémoire best-effort : l'agent repartira de l'historique texte.
        }
    }

    private function key(string $scope, string $threadId): string
    {
        return 'agent_thread_'.hash('sha256', $scope."\0".$threadId);
    }
}
