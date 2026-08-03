<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OAuthTokenRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: OAuthTokenRepository::class)]
#[ORM\Table(name: 'oauth_tokens')]
#[ORM\UniqueConstraint(name: 'uniq_oauth_access_hash', columns: ['access_token_hash'])]
#[ORM\Index(name: 'idx_oauth_refresh_hash', columns: ['refresh_token_hash'])]
class OAuthToken
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 64)]
    private string $accessTokenHash = '';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $refreshTokenHash = null;

    #[ORM\ManyToOne(targetEntity: OAuthClient::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private OAuthClient $client;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $scopes = [];

    #[ORM\Column]
    private \DateTimeImmutable $accessExpiresAt;

    #[ORM\Column]
    private \DateTimeImmutable $refreshExpiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    /**
     * @param list<string> $scopes
     */
    public function __construct(
        string $accessTokenHash,
        string $refreshTokenHash,
        OAuthClient $client,
        User $user,
        array $scopes,
        \DateTimeImmutable $accessExpiresAt,
        \DateTimeImmutable $refreshExpiresAt,
    ) {
        $this->id = Uuid::v7();
        $this->accessTokenHash = $accessTokenHash;
        $this->refreshTokenHash = $refreshTokenHash;
        $this->client = $client;
        $this->user = $user;
        $this->scopes = array_values($scopes);
        $this->accessExpiresAt = $accessExpiresAt;
        $this->refreshExpiresAt = $refreshExpiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAccessTokenHash(): string
    {
        return $this->accessTokenHash;
    }

    public function getRefreshTokenHash(): ?string
    {
        return $this->refreshTokenHash;
    }

    public function getClient(): OAuthClient
    {
        return $this->client;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    /**
     * @return list<string>
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function getAccessExpiresAt(): \DateTimeImmutable
    {
        return $this->accessExpiresAt;
    }

    public function getRefreshExpiresAt(): \DateTimeImmutable
    {
        return $this->refreshExpiresAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(): void
    {
        $this->revokedAt = new \DateTimeImmutable();
        $this->refreshTokenHash = null;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function isAccessExpired(): bool
    {
        return $this->accessExpiresAt <= new \DateTimeImmutable();
    }

    public function isRefreshExpired(): bool
    {
        return $this->refreshExpiresAt <= new \DateTimeImmutable();
    }

    public function touch(): void
    {
        $this->lastUsedAt = new \DateTimeImmutable();
    }
}
