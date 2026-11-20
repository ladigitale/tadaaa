<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Artifact;
use App\Entity\ArtifactPlace;
use App\Entity\ArtifactVisibility;
use App\Entity\User;
use App\Repository\ArtifactPlaceRepository;
use App\Repository\ArtifactRepository;
use App\Repository\ArtifactVersionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Lieux partagés d'un artefact (`data.places.<nom> = {maxBytes, maxRecords, radius}`) : n'importe quel visiteur
 * pose un objet JSON à une position GPS ; tous les visiteurs lisent ceux qui sont proches d'eux.
 *
 * Garde-fous : taille par lieu (maxBytes ≤ HARD_MAX_BYTES), nombre de lieux par artefact (maxRecords), débit par
 * IP (limiteur HTTP + plafond quotidien), signalements (masqué à HIDE_AT), suppression par le poseur (jeton) ou
 * par un membre du jeu de données de l'artefact. Mêmes droits de lecture que l'artefact.
 */
final class ArtifactPlaceService
{
    public const HARD_MAX_BYTES = 400_000;
    public const DEFAULT_MAX_BYTES = 150_000;
    public const HARD_MAX_RECORDS = 5000;
    public const DEFAULT_MAX_RECORDS = 1000;
    public const MAX_RADIUS = 5000;
    public const LIST_LIMIT = 80;
    public const DAILY_PER_IP = 40;
    public const HIDE_AT = 3;
    private const SUMMARY_KEYS = 16;
    /** Carte : au-delà, groupes par case de grille (MAP_GRID × MAP_GRID cases sur la zone). */
    public const MAP_LIMIT = 300;
    private const MAP_GRID = 16;
    private const SUMMARY_STRING = 200;

    public function __construct(
        private readonly ArtifactRepository $artifacts,
        private readonly ArtifactVersionRepository $versions,
        private readonly ArtifactPlaceRepository $places,
        private readonly DatasetAccessService $access,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Lieux à moins de `radius` m du point (ou les plus récents sans point), du plus proche au plus lointain.
     *
     * @return array{items: list<array<string, mixed>>, radius: int, moderator: bool}
     */
    public function list(string $slug, ?string $linkToken, string $collection, ?float $lat, ?float $lon, ?int $radius, ?User $user, bool $withHidden = false): array
    {
        [$artifact, $conf] = $this->open($slug, $linkToken, $user, $collection);
        $moderator = $this->isModerator($artifact, $user);
        $withHidden = $withHidden && $moderator;
        $r = max(10, min(self::MAX_RADIUS, $radius ?? $conf['radius']));
        if ($lat === null || $lon === null) {
            $rows = $this->places->findLatest($artifact, $collection, $withHidden, self::LIST_LIMIT);
            $items = array_map(fn (ArtifactPlace $p) => $this->row($p, null), $rows);

            return ['items' => $items, 'radius' => $r, 'moderator' => $moderator];
        }
        self::checkPoint($lat, $lon);
        $dLat = $r / 111_320;
        $dLon = $r / (111_320 * max(0.01, cos(deg2rad($lat))));
        $rows = $this->places->findInBox($artifact, $collection, $lat - $dLat, $lat + $dLat, $lon - $dLon, $lon + $dLon, $withHidden, 400);
        $items = [];
        foreach ($rows as $p) {
            $d = self::distance($lat, $lon, $p->getLat(), $p->getLon());
            if ($d <= $r) {
                $items[] = $this->row($p, $d);
            }
        }
        usort($items, static fn (array $a, array $b) => $a['distance'] <=> $b['distance']);

        return ['items' => \array_slice($items, 0, self::LIST_LIMIT), 'radius' => $r, 'moderator' => $moderator];
    }

    /**
     * Lieux d'une zone de carte (bbox = [ouest, sud, est, nord] en degrés) : les lieux eux-mêmes (champs courts)
     * jusqu'à MAP_LIMIT, au-delà des groupes par cases de grille {lat, lon, count} pour dézoomer sans tout charger.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox
     *
     * @return array{items: list<array<string, mixed>>, clusters: list<array{lat: float, lon: float, count: int}>, total: int, bbox: list<float>}
     */
    public function listBox(string $slug, ?string $linkToken, string $collection, array $bbox, ?User $user): array
    {
        [$artifact] = $this->open($slug, $linkToken, $user, $collection);
        [$w, $s, $e, $n] = $bbox;
        foreach ($bbox as $v) {
            if (!is_finite($v)) {
                throw new BadRequestHttpException('bbox : quatre nombres ouest,sud,est,nord.');
            }
        }
        $s = max(-90.0, min(90.0, $s));
        $n = max(-90.0, min(90.0, $n));
        if ($s > $n) {
            [$s, $n] = [$n, $s];
        }
        // Zone à cheval sur l'antiméridien ou plus large que le globe : tout le tour.
        if ($w > $e || $e - $w >= 360) {
            [$w, $e] = [-180.0, 180.0];
        }
        $w = max(-180.0, $w);
        $e = min(180.0, $e);
        $total = $this->places->countInBox($artifact, $collection, $s, $n, $w, $e);
        $box = [round($w, 6), round($s, 6), round($e, 6), round($n, 6)];
        if ($total <= self::MAP_LIMIT) {
            $rows = $this->places->findInBox($artifact, $collection, $s, $n, $w, $e, false, self::MAP_LIMIT);

            return ['items' => array_map(fn (ArtifactPlace $p) => $this->row($p, null), $rows), 'clusters' => [], 'total' => $total, 'bbox' => $box];
        }
        $cellLat = max(1e-5, ($n - $s) / self::MAP_GRID);
        $cellLon = max(1e-5, ($e - $w) / self::MAP_GRID);

        return ['items' => [], 'clusters' => $this->places->clustersInBox($artifact, $collection, $s, $n, $w, $e, $cellLat, $cellLon, self::MAP_GRID * self::MAP_GRID), 'total' => $total, 'bbox' => $box];
    }

    /** @return array<string, mixed> */
    public function get(string $slug, ?string $linkToken, string $collection, string $id, ?User $user): array
    {
        [$artifact] = $this->open($slug, $linkToken, $user, $collection);
        $p = $this->find($artifact, $collection, $id);
        if ($p->isHidden() && !$this->isModerator($artifact, $user)) {
            throw new NotFoundHttpException('Lieu introuvable.');
        }

        return $this->row($p, null) + ['body' => $p->getBody()];
    }

    /**
     * @param mixed $body objet JSON du lieu
     *
     * @return array{id: string, deleteToken: string, createdAt: string}
     */
    public function create(string $slug, ?string $linkToken, string $collection, mixed $lat, mixed $lon, mixed $body, int $sizeBytes, ?User $user, string $ip): array
    {
        [$artifact, $conf] = $this->open($slug, $linkToken, $user, $collection);
        if (!is_numeric($lat) || !is_numeric($lon)) {
            throw new BadRequestHttpException('lat et lon (nombres) requis.');
        }
        $lat = (float) $lat;
        $lon = (float) $lon;
        self::checkPoint($lat, $lon);
        if (!\is_array($body) || ($body !== [] && array_is_list($body))) {
            throw new BadRequestHttpException('body : objet JSON attendu.');
        }
        if ($sizeBytes > $conf['maxBytes']) {
            throw new BadRequestHttpException(sprintf('Lieu trop volumineux (%d Ko au plus).', intdiv($conf['maxBytes'], 1024)));
        }
        if ($this->places->countFor($artifact, $collection) >= $conf['maxRecords']) {
            throw new ConflictHttpException('Cet artefact a atteint son nombre maximal de lieux.');
        }
        $ipHash = hash('sha256', $artifact->getId()->toRfc4122().'|'.$ip);
        if ($this->places->countRecentByIp($artifact, $ipHash, new \DateTimeImmutable('-1 day')) >= self::DAILY_PER_IP) {
            throw new TooManyRequestsHttpException(3600, 'Trop de lieux posés aujourd’hui depuis ce réseau.');
        }
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $place = new ArtifactPlace($artifact, $collection, round($lat, 7), round($lon, 7), $body, self::summary($body), $sizeBytes, hash('sha256', $token), $ipHash);
        $place->setAuthor($user);
        $this->em->persist($place);
        $this->em->flush();

        return ['id' => $place->getId()->toRfc4122(), 'deleteToken' => $token, 'createdAt' => $place->getCreatedAt()->format(\DateTimeInterface::ATOM)];
    }

    public function delete(string $slug, ?string $linkToken, string $collection, string $id, ?string $deleteToken, ?User $user): void
    {
        [$artifact] = $this->open($slug, $linkToken, $user, $collection);
        $p = $this->find($artifact, $collection, $id);
        $byToken = $deleteToken !== null && $deleteToken !== '' && hash_equals($p->getDeleteTokenHash(), hash('sha256', $deleteToken));
        $byAuthor = $user !== null && $p->getAuthor() !== null && $p->getAuthor()->getId()->equals($user->getId());
        if (!$byToken && !$byAuthor && !$this->isModerator($artifact, $user)) {
            throw new AccessDeniedHttpException('Seul le poseur ou un membre de l’artefact peut supprimer ce lieu.');
        }
        $this->em->remove($p);
        $this->em->flush();
    }

    /** @return array{reports: int, hidden: bool} */
    public function report(string $slug, ?string $linkToken, string $collection, string $id, ?User $user): array
    {
        [$artifact] = $this->open($slug, $linkToken, $user, $collection);
        $p = $this->find($artifact, $collection, $id);
        $p->report(self::HIDE_AT);
        $this->em->flush();

        return ['reports' => $p->getReports(), 'hidden' => $p->isHidden()];
    }

    /** Membre : masque ou réaffiche un lieu signalé. */
    public function setHidden(string $slug, ?string $linkToken, string $collection, string $id, bool $hidden, ?User $user): void
    {
        [$artifact] = $this->open($slug, $linkToken, $user, $collection);
        if (!$this->isModerator($artifact, $user)) {
            throw new AccessDeniedHttpException('Réservé aux membres de l’artefact.');
        }
        $this->find($artifact, $collection, $id)->setHidden($hidden);
        $this->em->flush();
    }

    /**
     * Configuration d'une collection de lieux déclarée par le document.
     *
     * @return array{maxBytes: int, maxRecords: int, radius: int}
     */
    public static function config(mixed $def): array
    {
        $d = \is_array($def) ? $def : [];

        return [
            'maxBytes' => max(1024, min(self::HARD_MAX_BYTES, \is_int($d['maxBytes'] ?? null) ? $d['maxBytes'] : self::DEFAULT_MAX_BYTES)),
            'maxRecords' => max(1, min(self::HARD_MAX_RECORDS, \is_int($d['maxRecords'] ?? null) ? $d['maxRecords'] : self::DEFAULT_MAX_RECORDS)),
            'radius' => max(10, min(self::MAX_RADIUS, \is_int($d['radius'] ?? null) ? $d['radius'] : 300)),
        ];
    }

    /** Distance en mètres (haversine). */
    public static function distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2 + cos($p1) * cos($p2) * sin(deg2rad($lon2 - $lon1) / 2) ** 2;

        return 2 * 6_371_008.8 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Champs courts du corps, servis dans les listes : textes ≤ 200 caractères, nombres, booléens (16 au plus).
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public static function summary(array $body): array
    {
        $out = [];
        foreach ($body as $k => $v) {
            if (\count($out) >= self::SUMMARY_KEYS) {
                break;
            }
            if (!\is_string($k) || \in_array($k, ['id', 'lat', 'lon', 'distance', 'mine', 'hidden', 'reports'], true)) {
                continue;
            }
            if (\is_string($v) && mb_strlen($v) <= self::SUMMARY_STRING && !str_starts_with($v, 'data:')) {
                $out[$k] = $v;
            } elseif (\is_int($v) || \is_float($v) || \is_bool($v)) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    private static function checkPoint(float $lat, float $lon): void
    {
        if (!is_finite($lat) || !is_finite($lon) || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            throw new BadRequestHttpException('Position invalide.');
        }
    }

    /** @return array<string, mixed> */
    private function row(ArtifactPlace $p, ?float $distance): array
    {
        $row = $p->getSummary() + [
            'id' => $p->getId()->toRfc4122(),
            'lat' => $p->getLat(),
            'lon' => $p->getLon(),
            'createdAt' => $p->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'sizeBytes' => $p->getSizeBytes(),
        ];
        if ($distance !== null) {
            $row['distance'] = round($distance, 1);
        }
        if ($p->isHidden() || $p->getReports() > 0) {
            $row['reports'] = $p->getReports();
            $row['hidden'] = $p->isHidden();
        }

        return $row;
    }

    private function find(Artifact $artifact, string $collection, string $id): ArtifactPlace
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Lieu introuvable.');
        }
        $p = $this->places->find(Uuid::fromString($id));
        if ($p === null || !$p->getArtifact()->getId()->equals($artifact->getId()) || $p->getCollection() !== $collection) {
            throw new NotFoundHttpException('Lieu introuvable.');
        }

        return $p;
    }

    private function isModerator(Artifact $artifact, ?User $user): bool
    {
        return $user !== null && $this->access->getRole($user, $artifact->getDataset()) !== null;
    }

    /**
     * Artefact lisible par le visiteur, dont la version en ligne déclare `data.places.<collection>`.
     *
     * @return array{0: Artifact, 1: array{maxBytes: int, maxRecords: int, radius: int}}
     */
    private function open(string $slug, ?string $linkToken, ?User $user, string $collection): array
    {
        $artifact = $this->artifacts->findOneBySlug($slug);
        if ($artifact === null) {
            throw new NotFoundHttpException('Artefact introuvable.');
        }
        $vis = $artifact->getVisibility();
        $open = $vis === ArtifactVisibility::Public
            || ($vis === ArtifactVisibility::Link && $artifact->getLinkToken() !== null && $linkToken !== null && hash_equals($artifact->getLinkToken(), $linkToken))
            || ($user !== null && $this->access->getRole($user, $artifact->getDataset()) !== null);
        if (!$open) {
            throw new NotFoundHttpException('Artefact introuvable.');
        }
        $version = $this->versions->findOneForArtifactVersion($artifact, $artifact->getLiveVersion());
        $def = $version?->getDocument()['data']['places'][$collection] ?? null;
        if (!\is_array($def)) {
            throw new NotFoundHttpException('Cet artefact ne déclare pas ces lieux.');
        }

        return [$artifact, self::config($def)];
    }
}
