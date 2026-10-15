<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\AgUi\RecordingEventSink;
use App\Entity\AgentConversation;
use App\Entity\User;
use App\Repository\AgentConversationRepository;

/**
 * Historique des conversations de l'agent, par utilisateur et profil : enregistre chaque
 * run (journal compact + mémoire de l'agent), liste, relit, renomme, supprime.
 * Le cache de {@see ThreadStore} reste le chemin rapide ; la base reprend la main
 * quand il a expiré (réhydratation avant le run).
 */
final class ConversationHistory
{
    public function __construct(
        private readonly AgentConversationRepository $conversations,
        private readonly ThreadStore $threads,
    ) {
    }

    public static function scope(User $user, string $profile): string
    {
        return $user->getId()->toRfc4122().'|'.$profile;
    }

    /** Remet en cache la mémoire de l'agent si elle a expiré et qu'on l'a en base. */
    public function rehydrate(User $user, string $profile, string $threadId): void
    {
        $scope = self::scope($user, $profile);
        if ($this->threads->load($scope, $threadId) !== null) {
            return;
        }
        $conversation = $this->conversations->findOne($user, $profile, $threadId);
        $state = $conversation !== null ? self::decodeState($conversation->getState()) : null;
        if ($state !== null) {
            $this->threads->save($scope, $threadId, $state);
        }
    }

    /**
     * Enregistre le run terminé. Le journal du run s'ajoute à celui des runs précédents.
     */
    public function record(User $user, string $profile, RunInput $input, RecordingEventSink $recorder): void
    {
        $conversation = $this->conversations->findOne($user, $profile, $input->threadId)
            ?? new AgentConversation($user, $profile, $input->threadId);
        $previous = json_decode($conversation->getTranscript(), true);
        $entries = array_merge(\is_array($previous) ? $previous : [], $recorder->entries());
        $entries = $this->trim($entries);

        if ($conversation->getTitle() === '') {
            foreach ($entries as $entry) {
                if (($entry['role'] ?? null) === 'user') {
                    $conversation->setTitle(self::titleFrom((string) $entry['text']));
                    break;
                }
            }
        }
        $meta = $conversation->getMeta();
        if ($recorder->preview() !== null) {
            $meta['preview'] = $recorder->preview();
        }
        if ($recorder->published() !== null) {
            $meta['published'] = $recorder->published();
            $slug = \is_array($recorder->published()) ? ($recorder->published()['slug'] ?? null) : null;
            if (\is_string($slug)) {
                $conversation->setArtifactSlug($slug);
            }
        }
        if (isset($input->appContext['artifactSlug'])) {
            $conversation->setArtifactSlug($input->appContext['artifactSlug']);
        }
        $conversation->setTranscript((string) json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        $conversation->setMeta($meta);
        $state = $this->threads->load(self::scope($user, $profile), $input->threadId);
        if ($state !== null) {
            $conversation->setState(self::encodeState($state));
        }
        $conversation->touch();
        $this->conversations->save($conversation);
    }

    /** @return list<AgentConversation> */
    public function list(User $user, string $profile): array
    {
        return $this->conversations->recent($user, $profile);
    }

    public function find(User $user, string $profile, string $threadId): ?AgentConversation
    {
        return $this->conversations->findOne($user, $profile, $threadId);
    }

    public function rename(AgentConversation $conversation, string $title): void
    {
        $conversation->setTitle($title);
        $this->conversations->save($conversation);
    }

    public function delete(User $user, AgentConversation $conversation): void
    {
        $this->threads->forget(self::scope($user, $conversation->getProfile()), $conversation->getThreadId());
        $this->conversations->remove($conversation);
    }

    public static function titleFrom(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= 60) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, 59)).'…';
    }

    public static function encodeState(ThreadState $state): string
    {
        return base64_encode((string) gzcompress(serialize($state), 6));
    }

    public static function decodeState(?string $data): ?ThreadState
    {
        if ($data === null || $data === '') {
            return null;
        }
        $raw = base64_decode($data, true);
        $plain = $raw !== false ? @gzuncompress($raw) : false;
        if ($plain === false) {
            return null;
        }
        $state = @unserialize($plain, ['allowed_classes' => [ThreadState::class, \stdClass::class]]);

        return $state instanceof ThreadState ? $state : null;
    }

    /**
     * @param list<array<string, mixed>> $entries
     *
     * @return list<array<string, mixed>>
     */
    private function trim(array $entries): array
    {
        $json = static fn (array $e): int => \strlen((string) json_encode($e, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        while ($entries !== [] && $json($entries) > AgUi\RecordingEventSink::MAX_BYTES) {
            array_splice($entries, 0, max(1, intdiv(\count($entries), 4)));
        }

        return array_values($entries);
    }
}
