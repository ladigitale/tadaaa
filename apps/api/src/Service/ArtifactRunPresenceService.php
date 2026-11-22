<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ArtifactRun;
use App\Entity\ArtifactRunLink;
use App\Event\ArtifactRunSeen;
use App\Repository\ArtifactRunLinkRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Présence des runs reliés : chaque viewer de run en flux donne signe de vie toutes les 5 s
 * (`alive`) et prévient quand il part (`bye`). Un run voit comme membres les autres bouts de ses
 * liens actifs (dans un sens ou dans l'autre) dont le viewer s'est manifesté depuis moins de
 * {@see self::ONLINE_MS}. Quand cet ensemble change, le serveur pousse dans son flux
 * `{type: "presence", count, members: [{runId, slug, index, since, seen, role}]}` (le viewer le
 * publie dans `artifactRun.presence` et l'envoie au store du flux en action `presence`). `index`
 * est le rang d'arrivée (1, 2…), stable même quand un appareil part ou est retiré ; `role` est le
 * rôle d'appairage de l'appareil (null hors appairage à rôles).
 */
final class ArtifactRunPresenceService
{
    public const ONLINE_MS = 15_000;

    public function __construct(
        private readonly ArtifactRunLinkRepository $links,
        private readonly ArtifactRunStreamService $streams,
        private readonly Connection $connection,
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
        $roles = [];
        foreach ($this->connection->fetchAllAssociative('SELECT remote_run_id, role FROM artifact_run_pair_claims WHERE host_run_id = :r', ['r' => $host->getId()->toRfc4122()]) as $row) {
            $roles[strtolower((string) $row['remote_run_id'])] = (string) $row['role'];
        }
        $active = $this->links->findActiveTouching($host);
        $all = $this->links->findForRun($host);
        $members = self::members($active, $host, $now, $all, $roles);
        $desks = array_map(
            static fn (array $d): array => ['runId' => $d['runId'], 'slug' => $d['slug'], 'seen' => $d['seen']],
            self::members($active, $host, $now, $all, $roles, true),
        );
        $key = implode(',', array_column($members, 'runId')).'|'.implode(',', array_column($desks, 'runId'));
        $meta = $host->getStreamMeta();
        if (($meta['presenceKey'] ?? null) === $key) {
            return;
        }
        $presence = ['count' => \count($members), 'members' => $members, 'desks' => $desks, 'at' => $now];
        $meta['presenceKey'] = $key;
        $meta['presence'] = $presence;
        $host->setStreamMeta($meta);
        $this->em->flush();
        $this->streams->emitSystem($host, [(object) ['type' => 'presence', 'count' => $presence['count'], 'members' => $members, 'desks' => $desks]]);
    }

    /**
     * Membres en ligne d'un run. `index` = rang d'arrivée parmi tous les runs qui lui ont été reliés
     * (`$all`, liens inactifs compris : un départ ne décale pas les autres), sinon parmi les liens actifs.
     *
     * @param list<ArtifactRunLink>  $links liens actifs touchant le run
     * @param ?list<ArtifactRunLink> $all   tous les liens touchant le run, du plus ancien au plus récent
     * @param array<string, string>  $roles rôle d'appairage par runId d'appareil
     * @param bool                   $desks true : les guichets d'inscription seulement (exclus sinon)
     *
     * @return list<array{runId: string, slug: string, index: int, since: int, seen: int, role: ?string}>
     */
    public static function members(array $links, ArtifactRun $host, int $now, ?array $all = null, array $roles = [], bool $desks = false): array
    {
        $rank = [];
        foreach ($all ?? $links as $link) {
            $id = self::other($link, $host)->getId()->toRfc4122();
            if (($roles[$id] ?? '') !== ArtifactStream::DESK_ROLE) {
                $rank[$id] ??= \count($rank) + 1;
            }
        }
        $byRun = [];
        foreach ($links as $link) {
            $other = self::other($link, $host);
            $id = $other->getId()->toRfc4122();
            if (\array_key_exists($id, $byRun)) {
                continue;
            }
            if ($other->getStatus() !== ArtifactRun::STATUS_PENDING || $other->isExpired()) {
                $byRun[$id] = null;
                continue;
            }
            $seen = (int) ($other->getStreamMeta()['seen'] ?? 0);
            if ($seen <= 0 || $now - $seen > self::ONLINE_MS) {
                $byRun[$id] = null;
                continue;
            }
            $role = $roles[$id] ?? '';
            $byRun[$id] = [
                'runId' => $id,
                'slug' => $other->getArtifact()->getSlug(),
                'index' => $rank[$id] ?? \count($rank) + 1,
                'since' => $link->getCreatedAt()->getTimestamp() * 1000,
                'seen' => $seen,
                'role' => $role === '' ? null : $role,
            ];
        }
        $out = array_values(array_filter($byRun, static fn (?array $m): bool => $m !== null && ($m['role'] === ArtifactStream::DESK_ROLE) === $desks));
        usort($out, static fn (array $a, array $b): int => $a['index'] <=> $b['index']);

        return $out;
    }

    private static function other(ArtifactRunLink $link, ArtifactRun $run): ArtifactRun
    {
        return $link->getFromRun()->getId()->equals($run->getId()) ? $link->getToRun() : $link->getFromRun();
    }
}
