<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EmbedKeyRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: EmbedKeyRepository::class)]
#[ORM\Table(name: 'embed_keys')]
#[ORM\Index(name: 'idx_embed_owner', columns: ['owner_id'])]
#[ORM\UniqueConstraint(name: 'uniq_embed_token_hash', columns: ['token_hash'])]
class EmbedKey
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\ManyToOne(targetEntity: Dataset::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Dataset $dataset;

    #[ORM\Column(length: 120)]
    private string $name = '';

    /** Hash SHA-256 du secret (jamais stocké en clair). */
    #[ORM\Column(length: 64)]
    private string $tokenHash = '';

    /** Préfixe affiché (ex. emb_ab12…) pour retrouver la clé. */
    #[ORM\Column(length: 16)]
    private string $tokenPrefix = '';

    /**
     * Origines autorisées pour CORS (exact match). Vide = aucun Origin navigateur.
     * Entrée "*" = toutes les origines (déconseillé).
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $allowedOrigins = [];

    /**
     * Filtre OR sur les tags. Vide = tous les tags.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $tagIds = [];

    #[ORM\Column]
    private bool $includeDone = false;

    #[ORM\Column]
    private bool $includeDescription = false;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private int $rateLimitPerMinute = 60;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $lastOrigin = null;

    #[ORM\Column]
    private int $requestCount = 0;

    /** Stored as string for BIGINT portability. */
    #[ORM\Column(type: 'bigint')]
    private string $bytesServed = '0';

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    /**
     * @param list<string> $allowedOrigins
     * @param list<string> $tagIds
     */
    public function __construct(
        User $owner,
        Dataset $dataset,
        string $name,
        string $tokenHash,
        string $tokenPrefix,
        array $allowedOrigins = [],
        array $tagIds = [],
        bool $includeDone = false,
        bool $includeDescription = false,
        int $rateLimitPerMinute = 60,
    ) {
        $this->id = Uuid::v7();
        $this->owner = $owner;
        $this->dataset = $dataset;
        $this->name = trim($name);
        $this->tokenHash = $tokenHash;
        $this->tokenPrefix = $tokenPrefix;
        $this->allowedOrigins = array_values($allowedOrigins);
        $this->tagIds = array_values($tagIds);
        $this->includeDone = $includeDone;
        $this->includeDescription = $includeDescription;
        $this->rateLimitPerMinute = max(1, $rateLimitPerMinute);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getDataset(): Dataset
    {
        return $this->dataset;
    }

    public function setDataset(Dataset $dataset): void
    {
        $this->dataset = $dataset;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = trim($name);
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getTokenPrefix(): string
    {
        return $this->tokenPrefix;
    }

    public function rotateToken(string $tokenHash, string $tokenPrefix): void
    {
        $this->tokenHash = $tokenHash;
        $this->tokenPrefix = $tokenPrefix;
    }

    /**
     * @return list<string>
     */
    public function getAllowedOrigins(): array
    {
        return $this->allowedOrigins;
    }

    /**
     * @param list<string> $allowedOrigins
     */
    public function setAllowedOrigins(array $allowedOrigins): void
    {
        $this->allowedOrigins = array_values($allowedOrigins);
    }

    /**
     * @return list<string>
     */
    public function getTagIds(): array
    {
        return $this->tagIds;
    }

    /**
     * @param list<string> $tagIds
     */
    public function setTagIds(array $tagIds): void
    {
        $this->tagIds = array_values($tagIds);
    }

    public function isIncludeDone(): bool
    {
        return $this->includeDone;
    }

    public function setIncludeDone(bool $includeDone): void
    {
        $this->includeDone = $includeDone;
    }

    public function isIncludeDescription(): bool
    {
        return $this->includeDescription;
    }

    public function setIncludeDescription(bool $includeDescription): void
    {
        $this->includeDescription = $includeDescription;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function getRateLimitPerMinute(): int
    {
        return $this->rateLimitPerMinute;
    }

    public function setRateLimitPerMinute(int $rateLimitPerMinute): void
    {
        $this->rateLimitPerMinute = max(1, $rateLimitPerMinute);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getLastOrigin(): ?string
    {
        return $this->lastOrigin;
    }

    public function getRequestCount(): int
    {
        return $this->requestCount;
    }

    public function getBytesServed(): int
    {
        return (int) $this->bytesServed;
    }

    public function recordHit(?string $origin, int $bytes): void
    {
        $this->lastUsedAt = new \DateTimeImmutable();
        $this->lastOrigin = $origin !== null && $origin !== '' ? substr($origin, 0, 512) : null;
        ++$this->requestCount;
        $this->bytesServed = (string) ((int) $this->bytesServed + max(0, $bytes));
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(): void
    {
        $this->revokedAt = new \DateTimeImmutable();
        $this->active = false;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function allowsOrigin(?string $origin): bool
    {
        if ($origin === null || $origin === '') {
            // Non-browser clients (curl, ICS) — OK when no Origin header.
            return true;
        }

        foreach ($this->allowedOrigins as $allowed) {
            if ($allowed === '*') {
                return true;
            }
            if (strcasecmp($allowed, $origin) === 0) {
                return true;
            }
        }

        return false;
    }
}
