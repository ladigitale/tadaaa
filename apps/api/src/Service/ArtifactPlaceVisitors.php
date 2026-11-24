<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Artifact;
use App\Entity\ArtifactPlaceVisitor;
use App\Entity\User;
use App\Repository\ArtifactPlaceVisitorRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Blaze (pseudo et avatar) de qui écrit sur les lieux d'un artefact : celui de l'appareil (en-tête X-Place-Device)
 * ou, si un compte Tadaaa est connecté, celui de ce compte, retrouvé sur tous ses appareils.
 */
final class ArtifactPlaceVisitors
{
    public function __construct(
        private readonly ArtifactPlaceVisitorRepository $visitors,
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
     * Blaze de l'appareil ou du compte. Compte connecté : son blaze prime ; à défaut, celui de l'appareil lui est
     * rattaché s'il n'appartient à personne. `$create` : crée un blaze vide (rempli plus tard depuis « Mon blaze »,
     * ce qui met à jour les messages déjà écrits). Le flush est laissé à l'appelant.
     */
    public function resolve(Artifact $artifact, string $collection, ?string $device, ?User $user, bool $create): ?ArtifactPlaceVisitor
    {
        if ($user !== null && ($mine = $this->visitors->findByUser($artifact, $collection, $user)) !== null) {
            return $mine;
        }
        $hash = self::deviceHash($artifact, $device);
        $dev = $hash !== null ? $this->visitors->findByDevice($artifact, $collection, $hash) : null;
        if ($dev !== null && ($dev->getUser() === null || $user === null || $dev->getUser()->getId()->equals($user->getId()))) {
            if ($user !== null && $dev->getUser() === null) {
                $dev->setUser($user);
            }

            return $dev;
        }
        if (!$create || ($hash === null && $user === null)) {
            return null;
        }
        // Appareil déjà pris par un autre compte, ou pas de clé d'appareil : clé propre au compte.
        $key = ($dev === null && $hash !== null) ? $hash : hash('sha256', $artifact->getId()->toRfc4122().'|user|'.$user?->getId()->toRfc4122());
        $v = new ArtifactPlaceVisitor($artifact, $collection, $key);
        $v->setUser($user);
        $this->em->persist($v);

        return $v;
    }

    /** Chemin (relatif à l'hôte de l'API) de l'avatar visible, versionné par sa date de mise à jour ; null sinon. */
    public static function avatarPath(string $slug, string $collection, ?ArtifactPlaceVisitor $v): ?string
    {
        if ($v === null || $v->getAvatar() === null || $v->isHidden()) {
            return null;
        }

        return sprintf('/api/public/artifacts/%s/places/%s/avatars/%s?v=%d', $slug, $collection, $v->getId()->toRfc4122(), $v->getUpdatedAt()->getTimestamp());
    }
}
