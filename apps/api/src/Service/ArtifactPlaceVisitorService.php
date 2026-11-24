<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ArtifactPlaceVisitor;
use App\Entity\User;
use App\Repository\ArtifactPlaceVisitorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Blaze des visiteurs des lieux partagés : pseudo et avatar SVG dessiné, par collection de lieux, rattaché à l'appareil
 * (en-tête X-Place-Device) ou au compte Tadaaa connecté. Affiché sur leurs messages ; l'avatar est servi à part (SVG
 * brut, CSP stricte) : redessiné, il change partout.
 */
final class ArtifactPlaceVisitorService
{
    public function __construct(
        private readonly ArtifactPlaceService $places,
        private readonly ArtifactPlaceVisitors $resolver,
        private readonly ArtifactPlaceVisitorRepository $visitors,
        private readonly EntityManagerInterface $em,
        private readonly ModerationService $moderation,
    ) {
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

    /**
     * Blaze de l'appareil ou du compte, avec l'avatar en data: URL (à reprendre dans l'éditeur) ;
     * tout à null s'il n'en a pas encore.
     *
     * @return array{id: ?string, pseudo: ?string, avatar: ?string, avatarUrl: ?string, compte: bool}
     */
    public function getVisitor(string $slug, ?string $linkToken, string $collection, ?string $device, ?User $user): array
    {
        [$artifact] = $this->places->openCollection($slug, $linkToken, $user, $collection);
        $v = $this->resolver->resolve($artifact, $collection, $device, $user, false);
        $this->em->flush();

        return $this->out($slug, $collection, $v, true);
    }

    /** @return array{id: ?string, pseudo: ?string, avatar: ?string, avatarUrl: ?string, compte: bool} */
    public function saveVisitor(string $slug, ?string $linkToken, string $collection, ?string $device, mixed $pseudo, mixed $avatar, ?User $user): array
    {
        [$artifact] = $this->places->openCollection($slug, $linkToken, $user, $collection);
        if ($user === null && ArtifactPlaceVisitors::deviceHash($artifact, $device) === null) {
            throw new BadRequestHttpException('En-tête X-Place-Device requis.');
        }
        $pseudo = \is_string($pseudo) ? trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $pseudo) ?? '') : '';
        $pseudo = $pseudo === '' ? null : mb_substr($pseudo, 0, ArtifactPlaceVisitor::MAX_PSEUDO);
        $avatar = self::cleanAvatar($avatar);
        $v = $this->resolver->resolve($artifact, $collection, $device, $user, true);
        $v?->update($pseudo, $avatar);
        $this->em->flush();

        return $this->out($slug, $collection, $v, false);
    }

    /** @return array{id: ?string, pseudo: ?string, avatar: ?string, avatarUrl: ?string, compte: bool} */
    private function out(string $slug, string $collection, ?ArtifactPlaceVisitor $v, bool $withSource): array
    {
        return [
            'id' => $v?->getId()->toRfc4122(),
            'pseudo' => $v?->getPseudo(),
            'avatar' => $withSource ? $v?->getAvatar() : ArtifactPlaceVisitors::avatarPath($slug, $collection, $v),
            'avatarUrl' => ArtifactPlaceVisitors::avatarPath($slug, $collection, $v),
            'compte' => $v?->getUser() !== null,
        ];
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
        $this->moderation->reported($v->getArtifact(), 'visitor', $v->getId(), $v->getReports(), $v->isHidden(), 'blaze « '.($v->getPseudo() ?? 'sans pseudo').' »');
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
}
