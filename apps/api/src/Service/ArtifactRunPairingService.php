<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ArtifactRun;
use App\Entity\ArtifactVisibility;
use App\Repository\ArtifactRunRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Appairage d'un run en flux (`io.stream.pair = {remote, map?, back?, max?, ttl?, input?}`) :
 * le viewer du run (l'hôte) demande un code et l'affiche (QR) ; l'ouvrir sur un autre appareil lance
 * un run de l'artefact `remote` au nom du créateur de l'hôte et le relie à l'hôte
 * (`map` : remote → hôte, `back` : hôte → remote). Aucun jeton ne passe par le code : il ne sert
 * qu'à réclamer une place, `max` fois au plus par run hôte, pendant `ttl` secondes.
 *
 * Les deux sens d'usage : une régie montre de quoi la piloter (remote = télécommande), ou de quoi
 * ouvrir un écran récepteur (remote = récepteur, relié dans les deux sens).
 */
final class ArtifactRunPairingService
{
    /** Sans 0/O, 1/I/L : lisible à voix haute et tapable. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const CODE_LENGTH = 8;
    /** Un code qui expire dans moins de 60 s n'est plus montré : on en tire un neuf. */
    private const REUSE_MARGIN = 60;

    public function __construct(
        private readonly ArtifactRunRepository $runs,
        private readonly ArtifactService $artifacts,
        private readonly ArtifactRunService $runService,
        private readonly ArtifactRunLinkService $links,
        private readonly DatasetAccessService $access,
        private readonly Connection $connection,
    ) {
    }

    /**
     * Code d'appairage d'un run hôte (jeton du run) : réutilise le code en cours s'il reste valide.
     *
     * @return array<string, mixed>
     */
    public function issue(string $slug, string $runId, string $runToken): array
    {
        $host = $this->findRun($slug, $runId);
        if (!$host->matchesToken($runToken)) {
            throw new NotFoundHttpException('Run introuvable.');
        }
        $pair = $this->openHost($host);
        $remote = $this->remoteArtifact($host, $pair);
        $max = (int) ($pair['max'] ?? ArtifactStream::DEFAULT_PAIRS);
        $paired = $this->claimCount($host);
        $base = [
            'max' => $max,
            'paired' => $paired,
            'remaining' => max(0, $max - $paired),
            'remote' => $remote->getSlug(),
        ];
        if ($paired >= $max) {
            return $base + ['code' => null, 'url' => null, 'expiresAt' => null];
        }

        $row = $this->connection->fetchAssociative(
            'SELECT code, expires_at FROM artifact_run_pair_codes WHERE host_run_id = :r AND expires_at > :t ORDER BY expires_at DESC LIMIT 1',
            ['r' => $host->getId()->toRfc4122(), 't' => (new \DateTimeImmutable('+'.self::REUSE_MARGIN.' seconds'))->format('Y-m-d H:i:s')],
        );
        if ($row) {
            $code = (string) $row['code'];
            $expires = new \DateTimeImmutable((string) $row['expires_at']);
        } else {
            $ttl = (int) ($pair['ttl'] ?? ArtifactStream::DEFAULT_PAIR_TTL);
            $code = self::newCode();
            $expires = new \DateTimeImmutable('+'.$ttl.' seconds');
            $this->connection->insert('artifact_run_pair_codes', [
                'code' => $code,
                'host_run_id' => $host->getId()->toRfc4122(),
                'expires_at' => $expires->format('Y-m-d H:i:s'),
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
            // Ménage : codes expirés de ce run.
            $this->connection->executeStatement(
                'DELETE FROM artifact_run_pair_codes WHERE host_run_id = :r AND expires_at < :now',
                ['r' => $host->getId()->toRfc4122(), 'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
            );
        }

        return $base + [
            'code' => $code,
            'url' => self::withParams($this->remoteUrl($remote), ['pair' => $code]),
            'expiresAt' => $expires->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Réclamation d'un code par l'appareil qui l'a ouvert : lance le run de la télécommande / du
     * récepteur et crée les liens. Rend de quoi l'exécuter (run et jeton du run).
     *
     * @return array<string, mixed>
     */
    public function claim(string $remoteSlug, string $code): array
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^['.self::ALPHABET.']{'.self::CODE_LENGTH.'}$/', $code)) {
            throw new NotFoundHttpException('Code inconnu.');
        }
        $row = $this->connection->fetchAssociative('SELECT host_run_id, expires_at FROM artifact_run_pair_codes WHERE code = :c', ['c' => $code]);
        if (!$row) {
            throw new NotFoundHttpException('Code inconnu.');
        }
        if (new \DateTimeImmutable((string) $row['expires_at']) < new \DateTimeImmutable()) {
            throw new GoneHttpException('Ce code a expiré : en demander un nouveau à l’écran.');
        }
        $host = $this->runs->find(Uuid::fromString((string) $row['host_run_id']));
        if (!$host instanceof ArtifactRun) {
            throw new NotFoundHttpException('Code inconnu.');
        }
        $pair = $this->openHost($host);
        $remote = $this->remoteArtifact($host, $pair);
        if ($remote->getSlug() !== $remoteSlug) {
            throw new NotFoundHttpException('Ce code n’ouvre pas cet artefact.');
        }
        $max = (int) ($pair['max'] ?? ArtifactStream::DEFAULT_PAIRS);
        if ($this->claimCount($host) >= $max) {
            throw new ConflictHttpException(sprintf('Toutes les places sont prises (%d appareils au plus).', $max));
        }
        $user = $host->getCreatedBy() ?? throw new ConflictHttpException('Le run de cet écran n’a plus de propriétaire.');

        $in = $pair['input'] ?? null;
        // Objets décodés en stdClass, comme une entrée reçue en JSON ({} reste un objet).
        $input = ($in === null || $in === []) ? new \stdClass() : json_decode((string) json_encode($in), false);
        $created = $this->runService->run($user, $remote->getSlug(), $input, 'interactive');
        $remoteRunId = (string) $created['runId'];
        $remoteRun = $this->runs->find(Uuid::fromString($remoteRunId));
        $remoteIo = $remoteRun?->getIo() ?? [];
        $hostId = $host->getId()->toRfc4122();
        $linked = [];
        if (ArtifactStream::emits($remoteIo)) {
            $linked['toHost'] = $this->links->create($user, $remoteRunId, $hostId, \is_string($pair['map'] ?? null) ? $pair['map'] : null)['linkId'] ?? null;
        }
        if (\is_string($pair['back'] ?? null) && ArtifactStream::emits($host->getIo() ?? [])) {
            $linked['fromHost'] = $this->links->create($user, $hostId, $remoteRunId, $pair['back'])['linkId'] ?? null;
        }
        if ($linked === []) {
            throw new ConflictHttpException(sprintf('« %s » ne peut pas être relié : il lui faut une file d’envoi (io.stream.emit), ou back + une file d’envoi sur l’écran.', $remote->getSlug()));
        }
        $this->connection->insert('artifact_run_pair_claims', [
            'host_run_id' => $hostId,
            'remote_run_id' => $remoteRunId,
            'code' => $code,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return [
            'runId' => $remoteRunId,
            'runToken' => $created['runToken'] ?? null,
            'url' => $created['url'] ?? null,
            'slug' => $remote->getSlug(),
            'host' => ['slug' => $host->getArtifact()->getSlug(), 'runId' => $hostId],
            'links' => $linked,
            'expiresAt' => $created['expiresAt'] ?? null,
        ];
    }

    /** Appareils déjà reliés à un run hôte. */
    public function claimCount(ArtifactRun $host): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM artifact_run_pair_claims WHERE host_run_id = :r', ['r' => $host->getId()->toRfc4122()]);
    }

    /** @return array<string, mixed> */
    private function openHost(ArtifactRun $host): array
    {
        $pair = $host->getIo()['stream']['pair'] ?? null;
        if (!\is_array($pair) || !$host->hasStream()) {
            throw new BadRequestHttpException('Ce run ne propose pas d’appairage (io.stream.pair).');
        }
        if ($host->getStatus() !== ArtifactRun::STATUS_PENDING) {
            throw new ConflictHttpException('Cet écran est terminé.');
        }
        if ($host->isExpired()) {
            throw new GoneHttpException('Cet écran a expiré.');
        }

        return $pair;
    }

    /** @param array<string, mixed> $pair */
    private function remoteArtifact(ArtifactRun $host, array $pair): \App\Entity\Artifact
    {
        $slug = (string) ($pair['remote'] ?? '');
        try {
            $remote = $this->artifacts->findArtifactEntity($slug);
        } catch (NotFoundHttpException) {
            throw new ConflictHttpException(sprintf('pair.remote : artefact « %s » introuvable.', $slug));
        }
        $user = $host->getCreatedBy();
        if ($user === null || $this->access->getRole($user, $remote->getDataset())?->canWrite() !== true) {
            throw new ConflictHttpException(sprintf('pair.remote : « %s » doit appartenir au même compte que l’écran.', $slug));
        }
        if ($remote->getVisibility() === ArtifactVisibility::Private) {
            throw new ConflictHttpException(sprintf('pair.remote : « %s » est privé ; le passer en lien ou en public pour l’ouvrir depuis un autre appareil.', $slug));
        }

        return $remote;
    }

    private function remoteUrl(\App\Entity\Artifact $remote): string
    {
        return $this->artifacts->publicUrl($remote->getSlug(), $remote->getVisibility() === ArtifactVisibility::Link ? $remote->getLinkToken() : null);
    }

    private function findRun(string $slug, string $runId): ArtifactRun
    {
        $run = Uuid::isValid($runId) ? $this->runs->find(Uuid::fromString($runId)) : null;
        if (!$run instanceof ArtifactRun || $run->getArtifact()->getSlug() !== $slug) {
            throw new NotFoundHttpException('Run introuvable.');
        }

        return $run;
    }

    public static function newCode(): string
    {
        $out = '';
        $n = \strlen(self::ALPHABET);
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $out .= self::ALPHABET[random_int(0, $n - 1)];
        }

        return $out;
    }

    /** @param array<string, string> $params */
    private static function withParams(string $url, array $params): string
    {
        $hash = '';
        if (($pos = strpos($url, '#')) !== false) {
            $hash = substr($url, $pos);
            $url = substr($url, 0, $pos);
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($params).$hash;
    }
}
