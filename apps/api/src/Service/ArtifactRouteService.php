<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Artifact;
use App\Entity\ArtifactRoute;
use App\Entity\ArtifactRouteSound;
use App\Entity\User;
use App\Repository\ArtifactRouteRepository;
use App\Repository\ArtifactRouteSoundRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Parcours d'une collection de lieux : n'importe quel visiteur relie des lieux existants en étapes (ordre imposé
 * ou libre, déclenchement à l'étape ou en ambiance), avec un rayon et un son par étape. Mêmes droits de lecture
 * que l'artefact ; retrait par le créateur (jeton) ou un membre ; masqué au HIDE_AT-ième signalement.
 */
final class ArtifactRouteService
{
    public const MAP_LIMIT = 60;
    public const MAX_ROUTES = 500;
    public const DAILY_PER_IP = 10;
    /** Marche : 4,5 km/h. */
    private const WALK_M_PER_MIN = 75.0;

    public function __construct(
        private readonly ArtifactPlaceService $places,
        private readonly ArtifactRouteRepository $routes,
        private readonly ArtifactRouteSoundRepository $sounds,
        private readonly EntityManagerInterface $em,
        private readonly ModerationService $moderation,
    ) {
    }

    /**
     * Parcours qui touchent une zone de carte.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox ouest, sud, est, nord
     *
     * @return array{items: list<array<string, mixed>>}
     */
    public function listBox(string $slug, ?string $linkToken, string $collection, array $bbox, ?User $user): array
    {
        [$artifact] = $this->places->openCollection($slug, $linkToken, $user, $collection);
        [$w, $s, $e, $n] = $bbox;
        if (!is_finite($w + $s + $e + $n) || $s > $n || abs($s) > 90 || abs($n) > 90) {
            throw new BadRequestHttpException('bbox : ouest,sud,est,nord en degrés.');
        }

        return ['items' => array_map(fn (ArtifactRoute $r) => $this->row($r), $this->routes->inBox($artifact, $collection, $bbox, self::MAP_LIMIT))];
    }

    /** @return array<string, mixed> un parcours, ses étapes et les champs courts de leurs lieux */
    public function get(string $slug, ?string $linkToken, string $collection, string $id, ?User $user): array
    {
        [$artifact] = $this->places->openCollection($slug, $linkToken, $user, $collection);
        $r = $this->find($artifact, $collection, $id);
        $row = $this->row($r);
        foreach ($row['steps'] as $i => $step) {
            try {
                $p = $this->places->findPlace($artifact, $collection, (string) $step['place']);
                $row['steps'][$i]['missing'] = $p->isHidden();
                $row['steps'][$i]['summary'] = $p->getSummary();
                $row['steps'][$i]['messages'] = $p->getMessageCount();
            } catch (NotFoundHttpException) {
                $row['steps'][$i]['missing'] = true;
            }
        }

        return $row;
    }

    /** @return array{media: string} son propre d'une étape */
    public function sound(string $slug, ?string $linkToken, string $collection, string $id, int $index, ?User $user): array
    {
        [$artifact] = $this->places->openCollection($slug, $linkToken, $user, $collection);
        $r = $this->find($artifact, $collection, $id);
        $sound = $this->sounds->forStep($r, $index);
        if ($sound === null) {
            throw new NotFoundHttpException('Cette étape n’a pas de son propre.');
        }

        return ['media' => $sound->getMedia()];
    }

    /**
     * Nouveau parcours : `{title, description?, ordered, mode: etape|ambiance, pseudo?, steps: [{place, radius, sound:
     * balise|propre|aucun, media?: data:audio}]}`, lieux existants et visibles de la même collection.
     *
     * @return array{id: string, deleteToken: string}
     */
    public function create(string $slug, ?string $linkToken, string $collection, mixed $in, ?User $user, string $ip): array
    {
        [$artifact] = $this->places->openCollection($slug, $linkToken, $user, $collection);
        if (!\is_array($in)) {
            throw new BadRequestHttpException('Corps attendu : {title, steps: [...]}.');
        }
        $title = \is_string($in['title'] ?? null) ? trim(preg_replace('/\s+/u', ' ', $in['title']) ?? '') : '';
        if ($title === '' || mb_strlen($title) > ArtifactRoute::MAX_TITLE) {
            throw new BadRequestHttpException(sprintf('title : 1 à %d caractères.', ArtifactRoute::MAX_TITLE));
        }
        $description = \is_string($in['description'] ?? null) ? trim($in['description']) : '';
        if (mb_strlen($description) > ArtifactRoute::MAX_DESCRIPTION) {
            throw new BadRequestHttpException(sprintf('description : %d caractères au plus.', ArtifactRoute::MAX_DESCRIPTION));
        }
        $pseudo = \is_string($in['pseudo'] ?? null) ? mb_substr(trim($in['pseudo']), 0, 40) : '';
        $mode = \is_string($in['mode'] ?? null) && \in_array($in['mode'], ArtifactRoute::MODES, true) ? $in['mode'] : 'etape';
        $raw = $in['steps'] ?? null;
        if (!\is_array($raw) || !array_is_list($raw) || \count($raw) < ArtifactRoute::MIN_STEPS || \count($raw) > ArtifactRoute::MAX_STEPS) {
            throw new BadRequestHttpException(sprintf('steps : de %d à %d étapes.', ArtifactRoute::MIN_STEPS, ArtifactRoute::MAX_STEPS));
        }
        $steps = [];
        $sounds = [];
        $total = 0;
        $prev = null;
        $length = 0.0;
        $lats = [];
        $lons = [];
        foreach ($raw as $i => $s) {
            if (!\is_array($s) || !\is_string($s['place'] ?? null)) {
                throw new BadRequestHttpException(sprintf('steps[%d].place : id de lieu attendu.', $i));
            }
            $p = $this->places->findPlace($artifact, $collection, $s['place']);
            if ($p->isHidden()) {
                throw new BadRequestHttpException(sprintf('steps[%d] : ce lieu a été masqué.', $i));
            }
            $radius = is_numeric($s['radius'] ?? null) ? (int) $s['radius'] : 30;
            $radius = max(ArtifactRoute::MIN_RADIUS, min(ArtifactRoute::MAX_RADIUS, $radius));
            $sound = \in_array($s['sound'] ?? null, ['balise', 'propre', 'aucun'], true) ? $s['sound'] : 'balise';
            if ($sound === 'propre') {
                $media = $s['media'] ?? null;
                if (!\is_string($media) || !preg_match('#^data:audio/[a-z0-9.+-]+(;[a-z0-9=.+-]+)*;base64,[A-Za-z0-9+/=]+$#i', $media) || \strlen($media) > ArtifactRoute::MAX_SOUND) {
                    throw new BadRequestHttpException(sprintf('steps[%d].media : son data:audio/…;base64 de %d Ko au plus.', $i, intdiv(ArtifactRoute::MAX_SOUND, 1024)));
                }
                $total += \strlen($media);
                $sounds[(string) $i] = $media;
            }
            $lat = $p->getLat();
            $lon = $p->getLon();
            if ($prev !== null) {
                $length += ArtifactPlaceService::distance($prev[0], $prev[1], $lat, $lon);
            }
            $prev = [$lat, $lon];
            $lats[] = $lat;
            $lons[] = $lon;
            $steps[] = ['place' => $p->getId()->toRfc4122(), 'lat' => $lat, 'lon' => $lon, 'radius' => $radius, 'sound' => $sound];
        }
        if ($total > ArtifactRoute::MAX_SOUNDS) {
            throw new BadRequestHttpException(sprintf('Sons du parcours : %d Mo au plus en tout.', intdiv(ArtifactRoute::MAX_SOUNDS, 1_000_000)));
        }
        if ($this->routes->countFor($artifact, $collection) >= self::MAX_ROUTES) {
            throw new ConflictHttpException('Cet artefact a atteint son nombre maximal de parcours.');
        }
        $ipHash = hash('sha256', $artifact->getId()->toRfc4122().'|'.$ip);
        if ($this->routes->countRecentByIp($artifact, $ipHash, new \DateTimeImmutable('-1 day')) >= self::DAILY_PER_IP) {
            throw new TooManyRequestsHttpException(3600, 'Trop de parcours créés aujourd’hui depuis ce réseau.');
        }
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $r = new ArtifactRoute($artifact, $collection, $title, $description !== '' ? $description : null, ($in['ordered'] ?? true) !== false, $mode,
            $steps, [min($lats), max($lats), min($lons), max($lons)], (int) round($length), $pseudo !== '' ? $pseudo : null, $ipHash, hash('sha256', $token));
        $this->em->persist($r);
        foreach ($sounds as $i => $media) {
            $this->em->persist(new ArtifactRouteSound($r, (int) $i, $media));
        }
        $this->em->flush();

        return ['id' => $r->getId()->toRfc4122(), 'deleteToken' => $token];
    }

    public function delete(string $slug, ?string $linkToken, string $collection, string $id, ?string $deleteToken, ?User $user): void
    {
        [$artifact] = $this->places->openCollection($slug, $linkToken, $user, $collection);
        $r = $this->find($artifact, $collection, $id);
        $byToken = $deleteToken !== null && $deleteToken !== '' && hash_equals($r->getDeleteTokenHash(), hash('sha256', $deleteToken));
        if (!$byToken && !$this->places->canModerate($artifact, $user)) {
            throw new AccessDeniedHttpException('Seul le créateur ou un membre de l’artefact peut retirer ce parcours.');
        }
        $this->em->remove($r);
        $this->em->flush();
    }

    /** @return array{reports: int, hidden: bool} */
    public function report(string $slug, ?string $linkToken, string $collection, string $id, ?User $user): array
    {
        [$artifact] = $this->places->openCollection($slug, $linkToken, $user, $collection);
        $r = $this->find($artifact, $collection, $id);
        $r->report(ArtifactPlaceService::HIDE_AT);
        $this->em->flush();
        $this->moderation->reported($artifact, 'route', $r->getId(), $r->getReports(), $r->isHidden(), 'parcours « '.mb_substr($r->getTitle(), 0, 60).' »');

        return ['reports' => $r->getReports(), 'hidden' => $r->isHidden()];
    }

    /** @return array<string, mixed> */
    private function row(ArtifactRoute $r): array
    {
        $steps = [];
        foreach ($r->getSteps() as $i => $s) {
            $steps[] = $s + ['index' => $i, 'hasSound' => $s['sound'] === 'propre'];
        }

        return [
            'id' => $r->getId()->toRfc4122(),
            'title' => $r->getTitle(),
            'description' => $r->getDescription(),
            'ordered' => $r->isOrdered(),
            'mode' => $r->getMode(),
            'pseudo' => $r->getPseudo(),
            'steps' => $steps,
            'count' => \count($steps),
            'length' => $r->getLengthM(),
            'minutes' => (int) max(1, round($r->getLengthM() / self::WALK_M_PER_MIN)),
            'createdAt' => $r->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function find(Artifact $artifact, string $collection, string $id): ArtifactRoute
    {
        $r = Uuid::isValid($id) ? $this->routes->find(Uuid::fromString($id)) : null;
        if ($r === null || $r->isHidden() || !$r->getArtifact()->getId()->equals($artifact->getId()) || $r->getCollection() !== $collection) {
            throw new NotFoundHttpException('Parcours introuvable.');
        }

        return $r;
    }
}
