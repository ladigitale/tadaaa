<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ArtifactRun;
use App\Entity\ArtifactRunLink;
use App\Event\ArtifactRunSeen;
use App\Repository\ArtifactRunLinkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Présence des runs reliés : chaque viewer de run en flux donne signe de vie toutes les 5 s
 * (`alive`) et prévient quand il part (`bye`). Un run voit comme membres les autres bouts de ses
 * liens actifs (dans un sens ou dans l'autre) dont le viewer s'est manifesté depuis moins de
 * {@see self::ONLINE_MS}. Quand cet ensemble change, le serveur pousse dans son flux
 * `{type: "presence", count, members: [{runId, slug, index, since, seen}]}` (le viewer le publie
 * dans `artifactRun.presence` et l'envoie au store du flux en action `presence`).
 */
final class ArtifactRunPresenceService
{
    public const ONLINE_MS = 15_000;

    public function __construct(
        private readonly ArtifactRunLinkRepository $links,
        private readonly ArtifactRunStreamService $streams,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener]
    public function onSeen(ArtifactRunSeen $event): void
    {
        $run = $event->run;
        // Le run lui-même (départs de ses membres repérés à son propre signe de vie) et les runs
        // dont il est membre (arrivée ou départ annoncé tout de suite).
        $targets = [$run->getId()->toRfc4122() => $run];
        foreach ($this->links->findActiveTouching($run) as $link) {
            $other = self::other($link, $run);
            $targets[$other->getId()->toRfc4122()] = $other;
        }
        foreach ($targets as $host) {
            try {
                $this->refresh($host);
            } catch (\Throwable $e) {
                $this->logger->warning('Présence des runs : mise à jour impossible', ['run' => $host->getId()->toRfc4122(), 'error' => $e->getMessage()]);
            }
        }
    }

    /** Recalcule la présence d'un run et la pousse dans son flux si elle a changé. */
    public function refresh(ArtifactRun $host): void
    {
        if (!$host->hasStream() || $host->getStatus() !== ArtifactRun::STATUS_PENDING || $host->isExpired() || $host->isStreamEnded()) {
            return;
        }
        $now = (int) floor(microtime(true) * 1000);
        $members = self::members($this->links->findActiveTouching($host), $host, $now);
        $key = implode(',', array_column($members, 'runId'));
        $meta = $host->getStreamMeta();
        if (($meta['presenceKey'] ?? null) === $key) {
            return;
        }
        $presence = ['count' => \count($members), 'members' => $members, 'at' => $now];
        $meta['presenceKey'] = $key;
        $meta['presence'] = $presence;
        $host->setStreamMeta($meta);
        $this->em->flush();
        $this->streams->emitSystem($host, [(object) ['type' => 'presence', 'count' => $presence['count'], 'members' => $members]]);
    }

    /**
     * Membres en ligne d'un run, dans l'ordre des liens (index 1, 2… stable tant que les liens restent).
     *
     * @param list<ArtifactRunLink> $links liens actifs touchant le run
     *
     * @return list<array{runId: string, slug: string, index: int, since: int, seen: int}>
     */
    public static function members(array $links, ArtifactRun $host, int $now): array
    {
        $byRun = [];
        $index = 0;
        foreach ($links as $link) {
            $other = self::other($link, $host);
            $id = $other->getId()->toRfc4122();
            if (isset($byRun[$id])) {
                continue;
            }
            $index++;
            if ($other->getStatus() !== ArtifactRun::STATUS_PENDING || $other->isExpired()) {
                continue;
            }
            $seen = (int) ($other->getStreamMeta()['seen'] ?? 0);
            if ($seen <= 0 || $now - $seen > self::ONLINE_MS) {
                $byRun[$id] = null;
                continue;
            }
            $byRun[$id] = [
                'runId' => $id,
                'slug' => $other->getArtifact()->getSlug(),
                'index' => $index,
                'since' => $link->getCreatedAt()->getTimestamp() * 1000,
                'seen' => $seen,
            ];
        }

        return array_values(array_filter($byRun));
    }

    private static function other(ArtifactRunLink $link, ArtifactRun $run): ArtifactRun
    {
        return $link->getFromRun()->getId()->equals($run->getId()) ? $link->getToRun() : $link->getFromRun();
    }
}
