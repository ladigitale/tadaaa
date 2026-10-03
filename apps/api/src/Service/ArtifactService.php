<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Artifact;
use App\Entity\ArtifactVersion;
use App\Entity\ArtifactVisibility;
use App\Entity\AuditLog;
use App\Entity\Dataset;
use App\Entity\User;
use App\Repository\ArtifactRepository;
use App\Repository\ArtifactVersionRepository;
use App\Repository\DatasetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

final class ArtifactService
{
    private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{2,63}$/';
    private const CONCORDE_VERSION = '4.9.98-visual-stack.3';

    public function __construct(
        private readonly ArtifactRepository $artifacts,
        private readonly ArtifactVersionRepository $versions,
        private readonly DatasetRepository $datasets,
        private readonly DatasetAccessService $access,
        private readonly ArtifactDocumentValidator $validator,
        private readonly StorageQuota $quota,
        private readonly AuditLogger $audit,
        private readonly EntityManagerInterface $em,
        private readonly ArtifactDataService $artifactData,
        private readonly ArtifactScriptsCatalog $scriptsCatalog,
        #[Autowire('%env(ARTIFACTS_PUBLIC_URL)%')]
        private readonly string $publicBaseUrl,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUser(User $user, ?string $datasetId = null): array
    {
        $dataset = null;
        if ($datasetId !== null && $datasetId !== '') {
            $dataset = $this->requireReadableDataset($user, $datasetId);
        }

        return array_map(
            fn (Artifact $a): array => $this->serializeSummary($a),
            $this->artifacts->findAccessibleForUser($user, $dataset),
        );
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    public function create(
        User $user,
        string $datasetId,
        string $title,
        array $document,
        ?string $slug = null,
        string $visibility = 'private',
        ?string $description = null,
        ?string $ip = null,
    ): array {
        $dataset = $this->requireWritableDataset($user, $datasetId);
        $vis = $this->parseVisibility($visibility);
        $result = $this->validator->validate($document);
        if (!$result['valid']) {
            throw new BadRequestHttpException('Document SDUI invalide : '.$this->formatErrors($result['errors']));
        }

        $delta = \strlen((string) json_encode($document));
        $this->quota->assertCanGrow($dataset->getOwner(), $delta);

        $finalSlug = $this->allocateSlug($slug, $title);
        $artifact = new Artifact($dataset, $user, $finalSlug, $title);
        $artifact->setDescription($description);
        $artifact->setVisibility($vis);
        $artifact->setConcordeVersion(self::CONCORDE_VERSION);
        if ($vis === ArtifactVisibility::Link) {
            $artifact->setLinkToken($this->newLinkToken());
        }

        $version = new ArtifactVersion($artifact, 1, $document, $user, 'Publication initiale');
        $artifact->setCurrentVersion(1);
        $this->em->persist($artifact);
        $this->em->persist($version);
        $this->em->flush();
        $this->artifactData->syncCollectionsFromDocument($artifact, $document);

        $this->audit->log($dataset->getOwner(), AuditLog::CATEGORY_ARTIFACT, 'artifact.publish', [
            'artifactId' => $artifact->getId()->toRfc4122(),
            'slug' => $artifact->getSlug(),
            'visibility' => $vis->value,
            'version' => 1,
        ], $ip);

        return $this->serializeDetail($artifact, $document);
    }

    /** @return array<string, mixed> */
    public function getForUser(User $user, string $idOrSlug, ?int $versionNumber = null): array
    {
        $artifact = $this->requireReadableArtifact($user, $idOrSlug);
        $n = $versionNumber ?? $artifact->getCurrentVersion();
        $version = $this->versions->findOneForArtifactVersion($artifact, $n);
        if ($version === null) {
            throw new NotFoundHttpException('Version introuvable.');
        }

        return $this->serializeDetail($artifact, $version->getDocument());
    }

    /**
     * @param array<string, mixed> $patch
     *
     * @return array<string, mixed>
     */
    public function patch(User $user, string $idOrSlug, array $patch, ?string $ip = null): array
    {
        $artifact = $this->requireWritableArtifact($user, $idOrSlug);

        if (isset($patch['title']) && \is_string($patch['title'])) {
            $title = trim($patch['title']);
            if ($title === '' || mb_strlen($title) > 200) {
                throw new BadRequestHttpException('title invalide.');
            }
            $artifact->setTitle($title);
        }
        if (\array_key_exists('description', $patch)) {
            $desc = $patch['description'];
            $artifact->setDescription(\is_string($desc) ? $desc : null);
        }
        if (isset($patch['slug']) && \is_string($patch['slug'])) {
            $slug = strtolower(trim($patch['slug']));
            if (!preg_match(self::SLUG_PATTERN, $slug)) {
                throw new BadRequestHttpException('slug invalide.');
            }
            if ($this->artifacts->slugExists($slug, $artifact)) {
                throw new BadRequestHttpException('slug déjà utilisé.');
            }
            $artifact->setSlug($slug);
        }
        if (isset($patch['visibility']) && \is_string($patch['visibility'])) {
            $vis = $this->parseVisibility($patch['visibility']);
            $artifact->setVisibility($vis);
            if ($vis === ArtifactVisibility::Link && ($artifact->getLinkToken() === null || $artifact->getLinkToken() === '')) {
                $artifact->setLinkToken($this->newLinkToken());
            }
            if ($vis !== ArtifactVisibility::Link) {
                // keep token for restore to link later
            }
            $this->audit->log($artifact->getDataset()->getOwner(), AuditLog::CATEGORY_ARTIFACT, 'artifact.visibility', [
                'artifactId' => $artifact->getId()->toRfc4122(),
                'visibility' => $vis->value,
            ], $ip);
        }

        $this->em->flush();

        return $this->serializeSummary($artifact);
    }

    public function delete(User $user, string $idOrSlug, ?string $ip = null): void
    {
        $artifact = $this->requireWritableArtifact($user, $idOrSlug);
        $owner = $artifact->getDataset()->getOwner();
        $meta = [
            'artifactId' => $artifact->getId()->toRfc4122(),
            'slug' => $artifact->getSlug(),
        ];
        $this->em->remove($artifact);
        $this->em->flush();
        $this->audit->log($owner, AuditLog::CATEGORY_ARTIFACT, 'artifact.delete', $meta, $ip);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    public function putDocument(User $user, string $idOrSlug, array $document, ?string $note = null, ?string $ip = null): array
    {
        $artifact = $this->requireWritableArtifact($user, $idOrSlug);
        $result = $this->validator->validate($document);
        if (!$result['valid']) {
            throw new BadRequestHttpException('Document SDUI invalide : '.$this->formatErrors($result['errors']));
        }

        $delta = \strlen((string) json_encode($document));
        $this->quota->assertCanGrow($artifact->getDataset()->getOwner(), $delta);

        $next = $artifact->getCurrentVersion() + 1;
        $version = new ArtifactVersion($artifact, $next, $document, $user, $note);
        $artifact->setCurrentVersion($next);
        $artifact->setConcordeVersion(self::CONCORDE_VERSION);
        $this->em->persist($version);
        $this->em->flush();
        $this->artifactData->syncCollectionsFromDocument($artifact, $document);

        $this->audit->log($artifact->getDataset()->getOwner(), AuditLog::CATEGORY_ARTIFACT, 'artifact.update', [
            'artifactId' => $artifact->getId()->toRfc4122(),
            'version' => $next,
        ], $ip);

        return $this->serializeDetail($artifact, $document);
    }

    /** @return list<array<string, mixed>> */
    public function listVersions(User $user, string $idOrSlug): array
    {
        $artifact = $this->requireReadableArtifact($user, $idOrSlug);

        return array_map(
            static fn (ArtifactVersion $v): array => [
                'version' => $v->getVersion(),
                'createdAt' => $v->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'createdBy' => $v->getCreatedBy()->getEmail(),
                'note' => $v->getNote(),
                'current' => $v->getVersion() === $artifact->getCurrentVersion(),
            ],
            $this->versions->findAllForArtifact($artifact),
        );
    }

    /** @return array<string, mixed> */
    public function restoreVersion(User $user, string $idOrSlug, int $n, ?string $ip = null): array
    {
        $artifact = $this->requireWritableArtifact($user, $idOrSlug);
        $old = $this->versions->findOneForArtifactVersion($artifact, $n);
        if ($old === null) {
            throw new NotFoundHttpException('Version introuvable.');
        }

        return $this->putDocument(
            $user,
            $artifact->getId()->toRfc4122(),
            $old->getDocument(),
            sprintf('Restauration de la version %d', $n),
            $ip,
        );
    }

    /** @return array<string, mixed> */
    public function rotateLink(User $user, string $idOrSlug, ?string $ip = null): array
    {
        $artifact = $this->requireWritableArtifact($user, $idOrSlug);
        if ($artifact->getVisibility() !== ArtifactVisibility::Link) {
            throw new BadRequestHttpException('Rotation de lien réservée à la visibilité "link".');
        }
        $artifact->setLinkToken($this->newLinkToken());
        $this->em->flush();
        $this->audit->log($artifact->getDataset()->getOwner(), AuditLog::CATEGORY_ARTIFACT, 'artifact.rotate_link', [
            'artifactId' => $artifact->getId()->toRfc4122(),
        ], $ip);

        return $this->serializeSummary($artifact);
    }

    /**
     * Public / link / private (JWT member) read.
     *
     * @return array{body: array<string, mixed>, etag: string}
     */
    public function publicRead(string $slug, ?string $linkToken, ?User $user): array
    {
        $artifact = $this->artifacts->findOneBySlug($slug);
        if ($artifact === null) {
            throw new NotFoundHttpException('Artefact introuvable.');
        }

        $vis = $artifact->getVisibility();
        if ($vis === ArtifactVisibility::Public) {
            // ok
        } elseif ($vis === ArtifactVisibility::Link) {
            $expected = $artifact->getLinkToken();
            if ($expected === null || $linkToken === null || !hash_equals($expected, $linkToken)) {
                // JWT member of dataset may still read
                if ($user === null || $this->access->getRole($user, $artifact->getDataset()) === null) {
                    throw new NotFoundHttpException('Artefact introuvable.');
                }
            }
        } else { // private
            if ($user === null || $this->access->getRole($user, $artifact->getDataset()) === null) {
                throw new NotFoundHttpException('Artefact introuvable.');
            }
        }

        $version = $this->versions->findOneForArtifactVersion($artifact, $artifact->getCurrentVersion());
        if ($version === null) {
            throw new NotFoundHttpException('Artefact introuvable.');
        }

        $readableCollections = [];
        foreach ($artifact->getCollections() as $collection) {
            if ($collection->isPublicRead()) {
                $readableCollections[] = $collection->getName();
            }
        }

        $document = $version->getDocument();
        $scriptIds = \is_array($document) ? ($document['scripts'] ?? null) : null;
        $scriptAssets = $this->scriptsCatalog->resolve($scriptIds)['assets'];

        $body = [
            'id' => $artifact->getId()->toRfc4122(),
            'slug' => $artifact->getSlug(),
            'title' => $artifact->getTitle(),
            'description' => $artifact->getDescription(),
            'visibility' => $artifact->getVisibility()->value,
            'document' => $document,
            'scriptAssets' => $scriptAssets,
            'concordeVersion' => $artifact->getConcordeVersion(),
            'version' => $artifact->getCurrentVersion(),
            'updatedAt' => $artifact->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'collections' => $readableCollections,
            'canWrite' => $user !== null && $this->access->getRole($user, $artifact->getDataset())?->canWrite() === true,
        ];
        $etag = '"'.sha1((string) json_encode($body)).'"';

        return ['body' => $body, 'etag' => $etag];
    }

    /** @return array<string, mixed> */
    public function publicMeta(string $slug, ?string $linkToken, ?User $user): array
    {
        $full = $this->publicRead($slug, $linkToken, $user);

        return [
            'title' => $full['body']['title'],
            'description' => $full['body']['description'],
            'slug' => $full['body']['slug'],
            'updatedAt' => $full['body']['updatedAt'],
            'url' => $this->publicUrl($full['body']['slug'], $linkToken),
        ];
    }

    public function publicUrl(string $slug, ?string $linkToken = null): string
    {
        $base = rtrim($this->publicBaseUrl, '/');
        // Pas de "/" final : sinon SPA / Caddy + query `?k=` cassent le match router.
        $url = $base.'/'.rawurlencode(ltrim($slug, '/'));
        if ($linkToken !== null && $linkToken !== '') {
            $url .= '?k='.rawurlencode($linkToken);
        }

        return $url;
    }

    public function findArtifactEntity(string $idOrSlug): Artifact
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

    private function requireReadableArtifact(User $user, string $idOrSlug): Artifact
    {
        $artifact = $this->findArtifactEntity($idOrSlug);
        $this->access->assertCanRead($user, $artifact->getDataset());

        return $artifact;
    }

    private function requireWritableArtifact(User $user, string $idOrSlug): Artifact
    {
        $artifact = $this->findArtifactEntity($idOrSlug);
        $this->access->assertCanWrite($user, $artifact->getDataset());

        return $artifact;
    }

    private function requireReadableDataset(User $user, string $datasetId): Dataset
    {
        try {
            $uuid = Uuid::fromString($datasetId);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Jeu de données introuvable.');
        }
        $dataset = $this->datasets->find($uuid);
        if (!$dataset instanceof Dataset) {
            throw new NotFoundHttpException('Jeu de données introuvable.');
        }
        $this->access->assertCanRead($user, $dataset);

        return $dataset;
    }

    private function requireWritableDataset(User $user, string $datasetId): Dataset
    {
        $dataset = $this->requireReadableDataset($user, $datasetId);
        $this->access->assertCanWrite($user, $dataset);

        return $dataset;
    }

    private function parseVisibility(string $value): ArtifactVisibility
    {
        $vis = ArtifactVisibility::tryFrom($value);
        if ($vis === null) {
            throw new BadRequestHttpException('visibility doit être private|link|public.');
        }

        return $vis;
    }

    private function allocateSlug(?string $requested, string $title): string
    {
        if ($requested !== null && $requested !== '') {
            $slug = strtolower(trim($requested));
            if (!preg_match(self::SLUG_PATTERN, $slug)) {
                throw new BadRequestHttpException('slug invalide (^[a-z0-9][a-z0-9-]{2,63}$).');
            }
            if ($this->artifacts->slugExists($slug)) {
                throw new BadRequestHttpException('slug déjà utilisé.');
            }

            return $slug;
        }

        $base = $this->slugify($title);
        if (!preg_match(self::SLUG_PATTERN, $base)) {
            $base = 'art-'.bin2hex(random_bytes(3));
        }
        $candidate = $base;
        $i = 0;
        while ($this->artifacts->slugExists($candidate)) {
            $suffix = substr(bin2hex(random_bytes(2)), 0, 4);
            $candidate = substr($base, 0, 58).'-'.$suffix;
            if (++$i > 20) {
                $candidate = 'art-'.bin2hex(random_bytes(4));
                break;
            }
        }

        return $candidate;
    }

    private function slugify(string $title): string
    {
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) ?: $title;
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');
        if (\strlen($s) < 3) {
            $s = 'art-'.$s;
        }

        return substr($s, 0, 64);
    }

    private function newLinkToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @param list<array{path: string, message: string}> $errors
     */
    private function formatErrors(array $errors): string
    {
        return implode('; ', array_map(
            static fn (array $e): string => $e['path'].': '.$e['message'],
            array_slice($errors, 0, 5),
        ));
    }

    /** @return array<string, mixed> */
    private function serializeSummary(Artifact $artifact): array
    {
        $url = $this->publicUrl(
            $artifact->getSlug(),
            $artifact->getVisibility() === ArtifactVisibility::Link ? $artifact->getLinkToken() : null,
        );

        return [
            'id' => $artifact->getId()->toRfc4122(),
            'datasetId' => $artifact->getDataset()->getId()->toRfc4122(),
            'slug' => $artifact->getSlug(),
            'title' => $artifact->getTitle(),
            'description' => $artifact->getDescription(),
            'visibility' => $artifact->getVisibility()->value,
            'currentVersion' => $artifact->getCurrentVersion(),
            'concordeVersion' => $artifact->getConcordeVersion(),
            'url' => $url,
            'hasLinkToken' => $artifact->getLinkToken() !== null,
            'createdAt' => $artifact->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $artifact->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function serializeDetail(Artifact $artifact, array $document): array
    {
        $summary = $this->serializeSummary($artifact);
        $summary['document'] = $document;
        // Appelé uniquement pour des lecteurs authentifiés du jeu (getForUser / publish / update).
        $summary['collections'] = $this->artifactData->describeCollections($artifact, true);
        $summary['version'] = $artifact->getCurrentVersion();
        if ($artifact->getVisibility() === ArtifactVisibility::Link) {
            $summary['linkToken'] = $artifact->getLinkToken();
        }

        return $summary;
    }
}
