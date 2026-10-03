<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Artifact;
use App\Entity\ArtifactCollection;
use App\Entity\ArtifactCollectionScope;
use App\Entity\ArtifactRecord;
use App\Entity\ArtifactVisibility;
use App\Entity\ArtifactWriteMode;
use App\Entity\User;
use App\Repository\ArtifactCollectionRepository;
use App\Repository\ArtifactRecordRepository;
use App\Repository\ArtifactRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
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
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * Crée les collections déclarées dans document.data.sources (idempotent).
     * `writeMode` optionnel par source : none | members | authenticated ; `intake` (schéma) = collecte
     * anonyme contrôlée ; `publicRead` (bool, défaut true sauf collecte).
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
            $intake = \is_array($src['intake'] ?? null) && ArtifactIntakeSchema::validateDeclaration($src['intake']) === []
                ? ArtifactIntakeSchema::normalize($src['intake'])
                : null;
            $writeMode = $intake !== null
                ? ArtifactWriteMode::Intake
                : (\is_string($src['writeMode'] ?? null) ? ArtifactWriteMode::tryFrom($src['writeMode']) : null);
            if ($writeMode === ArtifactWriteMode::Intake && $intake === null) {
                $writeMode = ArtifactWriteMode::None; // intake sans schéma valide : fermé
            }
            // Une collecte n'est jamais lisible publiquement par défaut.
            $publicRead = \is_bool($src['publicRead'] ?? null) ? $src['publicRead'] : $intake === null;

            $collection = $this->collections->findOneByArtifactName($artifact, $name);
            if ($collection === null) {
                $collection = new ArtifactCollection($artifact, $name);
                $this->em->persist($collection);
            }
            // Le document fait foi : republier permet d'ouvrir/fermer l'écriture et la lecture.
            $collection->setPublicRead($publicRead);
            if ($writeMode !== null && $collection->getWriteMode() !== $writeMode) {
                $collection->setWriteMode($writeMode);
                if ($writeMode !== ArtifactWriteMode::Intake) {
                    $collection->closeIntake();
                }
            }
            $collection->setIntakeSchema($intake);
            if ($intake === null) {
                $collection->closeIntake();
                if ($writeMode === null && $collection->getWriteMode() === ArtifactWriteMode::Intake) {
                    $collection->setWriteMode(ArtifactWriteMode::None);
                }
            }
            if (!$publicRead && $collection->getReadToken() === null) {
                $collection->setReadToken(bin2hex(random_bytes(16)));
            }
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
     * Lecture publique : accès à l'artefact (public / lien `k` / membre) puis
     * collection publicRead, ou jeton de lecture secret `rk`, ou membre du jeu.
     *
     * @return list<array<string, mixed>>
     */
    public function readPublic(string $slug, string $collectionName, ?string $linkToken = null, ?string $readKey = null, ?User $user = null): array
    {
        $artifact = $this->resolveArtifact($slug);
        $this->assertPublicAccess($artifact, $linkToken, $user);
        $collection = $this->requireCollection($artifact, $collectionName);
        $isMember = $user !== null && $this->access->getRole($user, $artifact->getDataset()) !== null;
        $token = $collection->getReadToken();
        $secretOk = $token !== null && $readKey !== null && $readKey !== '' && hash_equals($token, $readKey);
        if (!$collection->isPublicRead() && !$secretOk && !$isMember) {
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
     * Envoi anonyme dans une collecte contrôlée.
     * Refusé si : pas de schéma, session fermée/expirée, code faux, quota atteint,
     * envoi trop rapproché pour cette IP, champ inconnu ou hors format.
     *
     * @return array{id: string}
     */
    public function createIntakeRecord(string $slug, string $collectionName, ?string $linkToken, mixed $data, ?string $code, string $clientIp): array
    {
        $artifact = $this->resolveArtifact($slug);
        $this->assertPublicAccess($artifact, $linkToken, null);
        $collection = $this->requireCollection($artifact, $collectionName);
        $schema = $collection->getIntakeSchema();
        $now = new \DateTimeImmutable();
        if ($schema === null || $collection->getWriteMode() !== ArtifactWriteMode::Intake) {
            throw new AccessDeniedHttpException('Cette collection n’accepte pas d’envois.');
        }
        if (!$collection->isIntakeOpen($now)) {
            throw new AccessDeniedHttpException('La collecte est fermée.');
        }
        $expected = $collection->getIntakeCode();
        if ($expected !== null && ($code === null || !hash_equals($expected, $code))) {
            throw new AccessDeniedHttpException('Code de session invalide.');
        }

        $result = ArtifactIntakeSchema::validateRecord($schema['fields'], $data);
        if ($result['errors'] !== []) {
            throw new BadRequestHttpException(implode(' ', $result['errors']));
        }

        $since = $collection->getIntakeOpenedAt() ?? $now;
        $max = $collection->getIntakeMaxRecords() ?? ArtifactIntakeSchema::DEFAULT_MAX_RECORDS;
        if ($this->records->countAnonymousSince($collection, $since) >= $max) {
            throw new AccessDeniedHttpException('Quota de la collecte atteint.');
        }

        // Intervalle par IP : désactivé par défaut (une classe partage souvent une seule IP publique).
        $interval = (int) ($schema['minInterval'] ?? ArtifactIntakeSchema::DEFAULT_MIN_INTERVAL);
        if ($interval > 0) {
            $item = $this->cache->getItem('intake_'.$collection->getId()->toBase32().'_'.hash('xxh128', $clientIp));
            if ($item->isHit()) {
                throw new TooManyRequestsHttpException($interval, 'Patientez quelques secondes avant un nouvel envoi.');
            }
            $item->set(1)->expiresAfter($interval);
            $this->cache->save($item);
        }

        $record = new ArtifactRecord($collection, $result['data'], null);
        $this->em->persist($record);
        $this->em->flush();

        return ['id' => $record->getId()->toRfc4122()];
    }

    /**
     * Ouvre (ou prolonge) une session de collecte. Réservé aux éditeurs du jeu.
     *
     * @return array<string, mixed>
     */
    public function openIntake(User $user, string $idOrSlug, string $collectionName, int $minutes, ?int $maxRecords = null): array
    {
        $artifact = $this->resolveArtifact($idOrSlug);
        $this->access->assertCanWrite($user, $artifact->getDataset());
        $collection = $this->requireCollection($artifact, $collectionName);
        $schema = $collection->getIntakeSchema();
        if ($schema === null || $collection->getWriteMode() !== ArtifactWriteMode::Intake) {
            throw new BadRequestHttpException('Collection sans schéma `intake` : déclarez-le dans data.sources.');
        }
        $minutes = max(1, min(ArtifactIntakeSchema::MAX_SESSION_MINUTES, $minutes));
        $max = $maxRecords ?? (int) $schema['maxRecords'];
        $max = max(1, min(ArtifactIntakeSchema::HARD_MAX_RECORDS, $max));
        $now = new \DateTimeImmutable();
        $code = ($schema['requireCode'] ?? false) ? str_pad((string) random_int(0, 9999), 4, '0', \STR_PAD_LEFT) : null;
        $collection->openIntake($now, $now->modify('+'.$minutes.' minutes'), $max, $code);
        $this->em->flush();

        return $this->describeCollection($collection, true);
    }

    /** @return array<string, mixed> */
    public function closeIntake(User $user, string $idOrSlug, string $collectionName, bool $purge = false): array
    {
        $artifact = $this->resolveArtifact($idOrSlug);
        $this->access->assertCanWrite($user, $artifact->getDataset());
        $collection = $this->requireCollection($artifact, $collectionName);
        $collection->closeIntake();
        if ($purge) {
            $this->records->deleteForCollection($collection);
        }
        $this->em->flush();

        return $this->describeCollection($collection, true);
    }

    /** Régénère le lien secret de lecture (invalide l'ancien). */
    public function rotateReadToken(User $user, string $idOrSlug, string $collectionName): array
    {
        $artifact = $this->resolveArtifact($idOrSlug);
        $this->access->assertCanWrite($user, $artifact->getDataset());
        $collection = $this->requireCollection($artifact, $collectionName);
        $collection->setReadToken(bin2hex(random_bytes(16)));
        $this->em->flush();

        return $this->describeCollection($collection, true);
    }

    /**
     * État des collections d'un artefact. Les secrets (code, jeton) ne sortent que pour les éditeurs.
     *
     * @return list<array<string, mixed>>
     */
    public function describeCollections(Artifact $artifact, bool $canWrite): array
    {
        $out = [];
        foreach ($this->collections->findBy(['artifact' => $artifact]) as $collection) {
            $out[] = $this->describeCollection($collection, $canWrite);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function describeCollection(ArtifactCollection $collection, bool $canWrite): array
    {
        $now = new \DateTimeImmutable();
        $schema = $collection->getIntakeSchema();
        $row = [
            'name' => $collection->getName(),
            'writeMode' => $collection->getWriteMode()->value,
            'publicRead' => $collection->isPublicRead(),
            'records' => $this->records->countForCollection($collection),
        ];
        if ($schema !== null) {
            $open = $collection->isIntakeOpen($now);
            $row['intake'] = [
                'fields' => $schema['fields'],
                'open' => $open,
                'openUntil' => $open ? $collection->getIntakeOpenUntil()?->format(\DateTimeInterface::ATOM) : null,
                'maxRecords' => $collection->getIntakeMaxRecords() ?? $schema['maxRecords'],
                'received' => $collection->getIntakeOpenedAt() !== null
                    ? $this->records->countAnonymousSince($collection, $collection->getIntakeOpenedAt())
                    : 0,
                'requireCode' => (bool) ($schema['requireCode'] ?? false),
                'minInterval' => $schema['minInterval'],
            ];
            if ($canWrite && $open && $collection->getIntakeCode() !== null) {
                $row['intake']['code'] = $collection->getIntakeCode();
            }
        }
        if ($canWrite && $collection->getReadToken() !== null) {
            $row['readToken'] = $collection->getReadToken();
        }

        return $row;
    }

    /** Même règle d'accès que la lecture publique d'un artefact. */
    private function assertPublicAccess(Artifact $artifact, ?string $linkToken, ?User $user): void
    {
        $vis = $artifact->getVisibility();
        if ($vis === ArtifactVisibility::Public) {
            return;
        }
        $isMember = $user !== null && $this->access->getRole($user, $artifact->getDataset()) !== null;
        if ($vis === ArtifactVisibility::Link) {
            $expected = $artifact->getLinkToken();
            if ($expected !== null && $linkToken !== null && hash_equals($expected, $linkToken)) {
                return;
            }
        }
        if (!$isMember) {
            throw new NotFoundHttpException('Artefact introuvable.');
        }
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
        if ($writeMode === ArtifactWriteMode::Members || $writeMode === ArtifactWriteMode::Intake) {
            $this->access->assertCanWrite($user, $artifact->getDataset());

            return;
        }
        // authenticated
        if ($this->access->getRole($user, $artifact->getDataset()) === null) {
            throw new AccessDeniedHttpException('Connexion requise pour écrire.');
        }
    }
}
