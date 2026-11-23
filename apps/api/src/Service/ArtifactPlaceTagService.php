<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Artifact;
use App\Entity\ArtifactPlaceTag;
use App\Entity\ArtifactPlaceVisitor;
use App\Entity\User;
use App\Repository\ArtifactPlaceTagRepository;
use App\Repository\ArtifactPlaceVisitorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * « Passés par là » : chaque appareil a un profil (pseudo + avatar SVG dessiné) par collection de lieux, et peut
 * se taguer sur un lieu, sur place seulement (position proche et mur reconnu). Un tag par appareil et par lieu :
 * un nouveau passage met sa date à jour. L'avatar est servi à part (SVG brut, CSP stricte) : le tag le référence,
 * un avatar redessiné change partout.
 */
final class ArtifactPlaceTagService
{
    public const LIST_LIMIT = 60;
    public const TAGS_PER_HOUR = 20;

    public function __construct(
        private readonly ArtifactPlaceService $places,
        private readonly ArtifactPlaceVisitorRepository $visitors,
        private readonly ArtifactPlaceTagRepository $tags,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Empreinte de la clé d'appareil (22 à 128 caractères base64url), ou null si absente ou invalide. */
    public static function deviceHash(Artifact $artifact, ?string $device): ?string
    {
        if ($device === null || preg_match('/^[A-Za-z0-9_-]{22,128}$/', $device) !== 1) {
            return null;
        }

        return hash('sha256', $artifact->getId()->toRfc4122().'|'.$device);
    }

    /**
     * Avatar accepté : data:image/svg+xml (base64 ou encodé), sans script, gestionnaire d'évènement,
     * objet étranger ni lien externe. Retourne la data: URL, ou null pour « pas d'avatar ».
     */
    public static function cleanAvatar(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!\is_string($v) || \strlen($v) > ArtifactPlaceVisitor::MAX_AVATAR) {
            throw new BadRequestHttpException(sprintf('avatar : SVG de %d Ko au plus.', intdiv(ArtifactPlaceVisitor::MAX_AVATAR, 1000)));
        }
        if (preg_match('#^data:image/svg\+xml(;charset=[a-z0-9-]+)?(;base64)?,(.*)$#is', $v, $m) !== 1) {
            throw new BadRequestHttpException('avatar : data:image/svg+xml attendu.');
        }
        $svg = $m[2] !== '' ? base64_decode($m[3], true) : rawurldecode($m[3]);
        if (!\is_string($svg) || !str_contains($svg, '<svg')) {
            throw new BadRequestHttpException('avatar : SVG illisible.');
        }
        if (self::unsafeSvg($svg)) {
            throw new BadRequestHttpException('avatar : le SVG ne doit contenir ni script, ni lien, ni contenu externe.');
        }

        return $v;
    }

    /** Sans le bloc <metadata> (source vectorielle compressée de sonic-sketch : du texte, inutile à l'affichage). */
    public static function stripMetadata(string $svg): string
    {
        return preg_replace('#<metadata\b[^>]*>.*?</metadata>#is', '', $svg) ?? $svg;
    }

    public static function unsafeSvg(string $svg): bool
    {
        $svg = self::stripMetadata($svg);

        return preg_match('#<\s*(script|foreignObject|iframe|object|embed|image)\b|\bon[a-z]+\s*=|javascript:|(?:xlink:)?href\s*=\s*["\']\s*(?!\#)|url\(\s*["\']?\s*(?!\#)|@import#i', $svg) === 1;
    }

    public static function svgOf(string $dataUrl): string
    {
        preg_match('#^data:image/svg\+xml(;charset=[a-z0-9-]+)?(;base64)?,(.*)$#is', $dataUrl, $m);

        return ($m[2] ?? '') !== '' ? (string) base64_decode($m[3], true) : rawurldecode($m[3] ?? '');
    }

    /** @return array{id: string, pseudo: ?string, avatar: ?string} */
    public function saveVisitor(string $slug, ?string $linkToken, string $collection, ?string $device, mixed $pseudo, mixed $avatar, ?User $user): array
    {
        [$artifact] = $this->places->openCollection($slug, $linkToken, $user, $collection);
        $hash = self::deviceHash($artifact, $device) ?? throw new BadRequestHttpException('En-tête X-Place-Device requis.');
        $pseudo = \is_string($pseudo) ? trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $pseudo) ?? '') : '';
        $pseudo = $pseudo === '' ? null : mb_substr($pseudo, 0, ArtifactPlaceVisitor::MAX_PSEUDO);
        $avatar = self::cleanAvatar($avatar);
        $v = $this->visitors->findByDevice($artifact, $collection, $hash);
        if ($v === null) {
            $v = new ArtifactPlaceVisitor($artifact, $collection, $hash);
            $this->em->persist($v);
        }
        $v->update($pseudo, $avatar);
        $this->em->flush();

        return ['id' => $v->getId()->toRfc4122(), 'pseudo' => $v->getPseudo(), 'avatar' => $this->avatarPath($slug, $collection, $v)];
    }

    /** @return array{count: int, at: string, first: bool} */
    public function tag(string $slug, ?string $linkToken, string $collection, string $id, ?string $device, mixed $at, ?User $user): array
    {
        [$artifact] = $this->places->openCollection($slug, $linkToken, $user, $collection);
        $p = $this->places->findPlace($artifact, $collection, $id);
        if ($p->isHidden()) {
            throw new NotFoundHttpException('Lieu introuvable.');
        }
        $hash = self::deviceHash($artifact, $device) ?? throw new BadRequestHttpException('En-tête X-Place-Device requis.');
        if (!ArtifactPlaceService::near($p, $at) || !\is_array($at) || ($at['seen'] ?? false) !== true) {
            throw new AccessDeniedHttpException('Pour te taguer, va sur place et vise le mur.');
        }
        $v = $this->visitors->findByDevice($artifact, $collection, $hash);
        $t = null;
        if ($v === null) {
            $v = new ArtifactPlaceVisitor($artifact, $collection, $hash);
            $this->em->persist($v);
        } else {
            if ($this->tags->countRecentByVisitor($v, new \DateTimeImmutable('-1 hour')) >= self::TAGS_PER_HOUR) {
                throw new TooManyRequestsHttpException(3600, 'Beaucoup de tags en une heure : reprends plus tard.');
            }
            $t = $this->tags->findOne($p, $v);
        }
        $first = $t === null;
        if ($t === null) {
            $t = new ArtifactPlaceTag($p, $v);
            $this->em->persist($t);
        } else {
            $t->touch();
        }
        $this->em->flush();

        return ['count' => $this->tags->countFor($p), 'at' => $t->getLastAt()->format(\DateTimeInterface::ATOM), 'first' => $first];
    }

    /** @return array{count: int, tags: list<array<string, mixed>>, mine: ?string} */
    public function list(string $slug, ?string $linkToken, string $collection, string $id, ?string $device, ?User $user): array
    {
        [$artifact] = $this->places->openCollection($slug, $linkToken, $user, $collection);
        $p = $this->places->findPlace($artifact, $collection, $id);
        if ($p->isHidden()) {
            throw new NotFoundHttpException('Lieu introuvable.');
        }
        $me = ($h = self::deviceHash($artifact, $device)) !== null ? $this->visitors->findByDevice($artifact, $collection, $h) : null;
        $rows = [];
        $mine = null;
        foreach ($this->tags->latestFor($p, self::LIST_LIMIT) as $t) {
            $v = $t->getVisitor();
            $isMe = $me !== null && $v->getId()->equals($me->getId());
            if ($isMe) {
                $mine = $t->getLastAt()->format(\DateTimeInterface::ATOM);
            }
            $rows[] = ['id' => $v->getId()->toRfc4122(), 'pseudo' => $v->getPseudo(), 'avatar' => $this->avatarPath($slug, $collection, $v),
                'at' => $t->getLastAt()->format(\DateTimeInterface::ATOM), 'mine' => $isMe];
        }
        if ($me !== null && $mine === null && ($t = $this->tags->findOne($p, $me)) !== null) {
            $mine = $t->getLastAt()->format(\DateTimeInterface::ATOM);
        }

        return ['count' => $this->tags->countFor($p), 'tags' => $rows, 'mine' => $mine];
    }

    /** SVG brut d'un avatar visible. */
    public function avatarSvg(string $slug, ?string $linkToken, string $collection, string $visitorId, ?User $user): string
    {
        $v = $this->visitor($slug, $linkToken, $collection, $visitorId, $user);
        if ($v->getAvatar() === null) {
            throw new NotFoundHttpException('Avatar introuvable.');
        }
        $svg = self::stripMetadata(self::svgOf($v->getAvatar()));
        if (self::unsafeSvg($svg)) {
            throw new NotFoundHttpException('Avatar introuvable.');
        }

        return $svg;
    }

    public function reportVisitor(string $slug, ?string $linkToken, string $collection, string $visitorId, ?User $user): void
    {
        $v = $this->visitor($slug, $linkToken, $collection, $visitorId, $user);
        $v->report();
        $this->em->flush();
    }

    private function visitor(string $slug, ?string $linkToken, string $collection, string $visitorId, ?User $user): ArtifactPlaceVisitor
    {
        [$artifact] = $this->places->openCollection($slug, $linkToken, $user, $collection);
        $v = Uuid::isValid($visitorId) ? $this->visitors->find(Uuid::fromString($visitorId)) : null;
        if ($v === null || $v->isHidden() || !$v->getArtifact()->getId()->equals($artifact->getId()) || $v->getCollection() !== $collection) {
            throw new NotFoundHttpException('Avatar introuvable.');
        }

        return $v;
    }

    /** Chemin (relatif à l'hôte de l'API) de l'avatar, versionné par sa date de mise à jour ; null sans avatar. */
    private function avatarPath(string $slug, string $collection, ArtifactPlaceVisitor $v): ?string
    {
        if ($v->getAvatar() === null) {
            return null;
        }

        return sprintf('/api/public/artifacts/%s/places/%s/avatars/%s?v=%d', $slug, $collection, $v->getId()->toRfc4122(), $v->getUpdatedAt()->getTimestamp());
    }
}
