<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Artifact;
use App\Entity\ArtifactSave;
use App\Entity\ArtifactVisibility;
use App\Entity\User;
use App\Repository\ArtifactRepository;
use App\Repository\ArtifactSaveRepository;
use App\Repository\ArtifactVersionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Sauvegardes en ligne des visiteurs d'un artefact (`data.persist = {providers, version, online: true}`).
 *
 * Une sauvegarde est retrouvée par sa clé secrète (lien « sur un autre appareil » ; seule l'empreinte est gardée)
 * ou, pour un visiteur connecté, par son compte (`me`) ; une sauvegarde par clé peut être rattachée au compte.
 * Mêmes droits de lecture que l'artefact (public, lien `k`, membre).
 */
final class ArtifactSaveService
{
    /** Taille maximale d'une sauvegarde (JSON encodé). */
    public const MAX_BYTES = 1_500_000;

    public function __construct(
        private readonly ArtifactRepository $artifacts,
        private readonly ArtifactVersionRepository $versions,
        private readonly ArtifactSaveRepository $saves,
        private readonly DatasetAccessService $access,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return array{data: array<string, mixed>, revision: int, updatedAt: string, owned: bool} */
    public function readByKey(string $slug, ?string $linkToken, string $key, ?User $user): array
    {
        $artifact = $this->openArtifact($slug, $linkToken, $user);
        $save = $this->saves->findByKeyHash($artifact, self::hashKey($key));
        if ($save === null) {
            throw new NotFoundHttpException('Aucune sauvegarde pour cette clé.');
        }

        return $this->serialize($save);
    }

    /** @return array{data: array<string, mixed>, revision: int, updatedAt: string, owned: bool} */
    public function readMine(string $slug, ?string $linkToken, ?User $user): array
    {
        $owner = $this->requireUser($user);
        $artifact = $this->openArtifact($slug, $linkToken, $owner);
        $save = $this->saves->findLatestForOwner($artifact, $owner);
        if ($save === null) {
            throw new NotFoundHttpException('Aucune sauvegarde sur ce compte.');
        }

        return $this->serialize($save);
    }

    /**
     * Écrit (ou crée) la sauvegarde de la clé ; un visiteur connecté la rattache à son compte si elle n'a pas
     * encore de propriétaire.
     *
     * @return array{revision: int, updatedAt: string, owned: bool}
     */
    public function writeByKey(string $slug, ?string $linkToken, string $key, mixed $data, int $sizeBytes, ?User $user): array
    {
        $artifact = $this->openArtifact($slug, $linkToken, $user);
        $data = $this->checkData($data, $sizeBytes);
        $hash = self::hashKey($key);
        $save = $this->saves->findByKeyHash($artifact, $hash);
        if ($save === null) {
            $save = new ArtifactSave($artifact, $hash);
            $this->em->persist($save);
        } elseif ($save->getOwner() !== null && $user !== null && $save->getOwner()->getId()->equals($user->getId()) === false) {
            // la clé d'un autre compte n'est pas reprise
            throw new AccessDeniedHttpException('Cette sauvegarde appartient à un autre compte.');
        }
        if ($user !== null && $save->getOwner() === null) {
            $save->setOwner($user);
        }
        $save->setData($data, $sizeBytes);
        $this->em->flush();

        return $this->meta($save);
    }

    /**
     * Sauvegarde du compte : la plus récente rattachée au compte, créée (clé aléatoire jamais montrée) s'il n'y en a pas.
     *
     * @return array{revision: int, updatedAt: string, owned: bool}
     */
    public function writeMine(string $slug, ?string $linkToken, mixed $data, int $sizeBytes, ?User $user): array
    {
        $owner = $this->requireUser($user);
        $artifact = $this->openArtifact($slug, $linkToken, $owner);
        $data = $this->checkData($data, $sizeBytes);
        $save = $this->saves->findLatestForOwner($artifact, $owner);
        if ($save === null) {
            $save = new ArtifactSave($artifact, self::hashKey(bin2hex(random_bytes(24))));
            $save->setOwner($owner);
            $this->em->persist($save);
        }
        $save->setData($data, $sizeBytes);
        $this->em->flush();

        return $this->meta($save);
    }

    public static function isValidKey(string $key): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{22,64}$/', $key) === 1;
    }

    private static function hashKey(string $key): string
    {
        if (!self::isValidKey($key)) {
            throw new BadRequestHttpException('Clé de sauvegarde invalide.');
        }

        return hash('sha256', $key);
    }

    private function requireUser(?User $user): User
    {
        if ($user === null) {
            throw new UnauthorizedHttpException('Bearer', 'Connexion requise.');
        }

        return $user;
    }

    /** @return array<string, mixed> */
    private function checkData(mixed $data, int $sizeBytes): array
    {
        if (!\is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new BadRequestHttpException('data : objet attendu.');
        }
        if ($sizeBytes > self::MAX_BYTES) {
            throw new BadRequestHttpException('Sauvegarde trop volumineuse.');
        }

        return $data;
    }

    /** Artefact lisible par le visiteur et dont le document accepte la sauvegarde en ligne. */
    private function openArtifact(string $slug, ?string $linkToken, ?User $user): Artifact
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
        $persist = $version?->getDocument()['data']['persist'] ?? null;
        if (!\is_array($persist) || ($persist['online'] ?? false) !== true) {
            throw new AccessDeniedHttpException('Cet artefact n’a pas de sauvegarde en ligne.');
        }

        return $artifact;
    }

    /** @return array{data: array<string, mixed>, revision: int, updatedAt: string, owned: bool} */
    private function serialize(ArtifactSave $save): array
    {
        return ['data' => $save->getData()] + $this->meta($save);
    }

    /** @return array{revision: int, updatedAt: string, owned: bool} */
    private function meta(ArtifactSave $save): array
    {
        return [
            'revision' => $save->getRevision(),
            'updatedAt' => $save->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'owned' => $save->getOwner() !== null,
        ];
    }
}
