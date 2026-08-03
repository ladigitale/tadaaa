<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OAuthAuthCodeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: OAuthAuthCodeRepository::class)]
#[ORM\Table(name: 'oauth_auth_codes')]
#[ORM\UniqueConstraint(name: 'uniq_oauth_auth_code_hash', columns: ['code_hash'])]
class OAuthAuthCode
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 64)]
    private string $codeHash = '';

    #[ORM\ManyToOne(targetEntity: OAuthClient::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private OAuthClient $client;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 2048)]
    private string $redirectUri = '';

    #[ORM\Column(length: 128)]
    private string $codeChallenge = '';

    #[ORM\Column(length: 16)]
    private string $codeChallengeMethod = 'S256';

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $scopes = [];

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<string> $scopes
     */
    public function __construct(
        string $codeHash,
        OAuthClient $client,
        User $user,
        string $redirectUri,
        string $codeChallenge,
        array $scopes,
        \DateTimeImmutable $expiresAt,
        string $codeChallengeMethod = 'S256',
    ) {
        $this->id = Uuid::v7();
        $this->codeHash = $codeHash;
        $this->client = $client;
        $this->user = $user;
        $this->redirectUri = $redirectUri;
        $this->codeChallenge = $codeChallenge;
        $this->codeChallengeMethod = $codeChallengeMethod;
        $this->scopes = array_values($scopes);
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCodeHash(): string
    {
        return $this->codeHash;
    }

    public function getClient(): OAuthClient
    {
        return $this->client;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRedirectUri(): string
    {
        return $this->redirectUri;
    }

    public function getCodeChallenge(): string
    {
        return $this->codeChallenge;
    }

    public function getCodeChallengeMethod(): string
    {
        return $this->codeChallengeMethod;
    }

    /**
     * @return list<string>
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function markUsed(): void
    {
        $this->usedAt = new \DateTimeImmutable();
    }

    public function isExpired(): bool
    {
        return $this->expiresAt <= new \DateTimeImmutable();
    }

    public function isUsed(): bool
    {
        return $this->usedAt !== null;
    }
}
