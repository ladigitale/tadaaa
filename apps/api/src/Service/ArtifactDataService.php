<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Artifact;
use App\Entity\ArtifactCollection;
use App\Entity\ArtifactCollectionScope;
use App\Entity\ArtifactRecord;
use App\Entity\ArtifactWriteMode;
use App\Entity\User;
use App\Repository\ArtifactCollectionRepository;
use App\Repository\ArtifactRecordRepository;
use App\Repository\ArtifactRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Données dynamiques par artefact (collections / records).
 */
final class ArtifactDataService
{
    public function __construct(
        private readonly ArtifactRepository $artifacts,
        private readonly ArtifactCollectionRepository $collections,
        private readonly ArtifactRecordRepository $records,
        private readonly DatasetAccessService $access,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Crée les collections déclarées dans document.data.sources (idempotent).
     * `writeMode` optionnel par source : none | members | authenticated.
     *
     * @param array<string, mixed> $document
     */
    public function syncCollectionsFromDocument(Artifact $artifact, array $document): void
    {
        $sources = $document['data']['sources'] ?? null;
        if (!\is_array($sources)) {
            return;
        }

        foreach ($sources as $sourceKey => $src) {
            if (!\is_array($src)) {
                continue;
            }
            $name = $src['collection'] ?? (is_string($sourceKey) ? $sourceKey : null);
            if (!\is_string($name) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name)) {
                continue;
            }
            $writeMode = \is_string($src['writeMode'] ?? null)
                ? ArtifactWriteMode::tryFrom($src['writeMode'])
                : null;

            $existing = $this->collections->findOneByArtifactName($artifact, $name);
            if ($existing !== null) {
                // Le document fait foi : republier permet d'ouvrir/fermer l'écriture.
                if ($writeMode !== null && $existing->getWriteMode() !== $writeMode) {
                    $existing->setWriteMode($writeMode);
                }
                continue;
            }
            $collection = new ArtifactCollection($artifact, $name);
            $collection->setPublicRead(true);
            if ($writeMode !== null) {
                $collection->setWriteMode($writeMode);
            }
            $this->em->persist($collection);
        }
        $this->em->flush();
    }

    /**
     * @return list<array{id: string, data: array<string, mixed>, createdAt: string, updatedAt: string}>
     */
    public function readForUser(User $user, string $idOrSlug, string $collectionName): array
    {
        $artifact = $this->resolveArtifact($idOrSlug);
        $this->access->assertCanRead($user, $artifact->getDataset());
        $collection = $this->requireCollection($artifact, $collectionName);

        $ownerFilter = $collection->getScope() === ArtifactCollectionScope::PerUser ? $user : null;

        return array_map(
            static fn (ArtifactRecord $r): array => [
                'id' => $r->getId()->toRfc4122(),
                'data' => $r->getData(),
                'createdAt' => $r->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'updatedAt' => $r->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            ],
            $this->records->findForCollection($collection, $ownerFilter),
        );
    }

    /**
     * Lecture publique si publicRead.
     *
     * @return list<array<string, mixed>>
     */
    public function readPublic(string $slug, string $collectionName): array
    {
        $artifact = $this->resolveArtifact($slug);
        $collection = $this->requireCollection($artifact, $collectionName);
        if (!$collection->isPublicRead()) {
            throw new NotFoundHttpException('Collection introuvable.');
        }

        return array_map(
            static fn (ArtifactRecord $r): array => [
                'id' => $r->getId()->toRfc4122(),
                'data' => $r->getData(),
                'createdAt' => $r->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'updatedAt' => $r->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            ],
            $this->records->findForCollection($collection, null),
        );
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{id: string, data: array<string, mixed>}
     */
    public function createRecord(User $user, string $idOrSlug, string $collectionName, array $data): array
    {
        $artifact = $this->resolveArtifact($idOrSlug);
        $collection = $this->requireCollection($artifact, $collectionName);
        $this->assertCanWriteCollection($user, $artifact, $collection);

        $record = new ArtifactRecord($collection, $data, $user);
        $this->em->persist($record);
        $this->em->flush();

        return [
            'id' => $record->getId()->toRfc4122(),
            'data' => $record->getData(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{id: string, data: array<string, mixed>}
     */
    public function updateRecord(User $user, string $idOrSlug, string $collectionName, string $recordId, array $data): array
    {
        $artifact = $this->resolveArtifact($idOrSlug);
        $collection = $this->requireCollection($artifact, $collectionName);
        $this->assertCanWriteCollection($user, $artifact, $collection);

        $record = $this->requireRecord($collection, $recordId);
        if ($collection->getScope() === ArtifactCollectionScope::PerUser) {
            $owner = $record->getCreatedBy();
            if ($owner === null || !$owner->getId()->equals($user->getId())) {
                throw new AccessDeniedHttpException('Record introuvable.');
            }
        }

        $record->setData($data);
        $this->em->flush();

        return [
            'id' => $record->getId()->toRfc4122(),
            'data' => $record->getData(),
        ];
    }

    public function deleteRecord(User $user, string $idOrSlug, string $collectionName, string $recordId): void
    {
        $artifact = $this->resolveArtifact($idOrSlug);
        $collection = $this->requireCollection($artifact, $collectionName);
        $this->assertCanWriteCollection($user, $artifact, $collection);

        $record = $this->requireRecord($collection, $recordId);
        if ($collection->getScope() === ArtifactCollectionScope::PerUser) {
            $owner = $record->getCreatedBy();
            if ($owner === null || !$owner->getId()->equals($user->getId())) {
                throw new AccessDeniedHttpException('Record introuvable.');
            }
        }

        $this->em->remove($record);
        $this->em->flush();
    }

    private function resolveArtifact(string $idOrSlug): Artifact
    {
        if (Uuid::isValid($idOrSlug)) {
            $artifact = $this->artifacts->find(Uuid::fromString($idOrSlug));
            if ($artifact instanceof Artifact) {
                return $artifact;
            }
        }
        $artifact = $this->artifacts->findOneBySlug($idOrSlug);
        if ($artifact === null) {
            throw new NotFoundHttpException('Artefact introuvable.');
        }

        return $artifact;
    }

    private function requireCollection(Artifact $artifact, string $name): ArtifactCollection
    {
        $collection = $this->collections->findOneByArtifactName($artifact, $name);
        if ($collection === null) {
            throw new NotFoundHttpException('Collection introuvable.');
        }

        return $collection;
    }

    private function requireRecord(ArtifactCollection $collection, string $recordId): ArtifactRecord
    {
        try {
            $uuid = Uuid::fromString($recordId);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Record introuvable.');
        }
        $record = $this->records->find($uuid);
        if (!$record instanceof ArtifactRecord || !$record->getCollection()->getId()->equals($collection->getId())) {
            throw new NotFoundHttpException('Record introuvable.');
        }

        return $record;
    }

    private function assertCanWriteCollection(User $user, Artifact $artifact, ArtifactCollection $collection): void
    {
        $writeMode = $collection->getWriteMode();
        if ($writeMode === ArtifactWriteMode::None) {
            throw new AccessDeniedHttpException('Cette collection est en lecture seule.');
        }
        if ($writeMode === ArtifactWriteMode::Members) {
            $this->access->assertCanWrite($user, $artifact->getDataset());

            return;
        }
        // authenticated
        if ($this->access->getRole($user, $artifact->getDataset()) === null) {
            throw new AccessDeniedHttpException('Connexion requise pour écrire.');
        }
    }
}
