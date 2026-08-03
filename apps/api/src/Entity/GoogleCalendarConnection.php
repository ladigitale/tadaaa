<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GoogleCalendarConnectionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: GoogleCalendarConnectionRepository::class)]
#[ORM\Table(name: 'google_calendar_connections')]
#[ORM\UniqueConstraint(name: 'uniq_gcal_connection_user', columns: ['user_id'])]
class GoogleCalendarConnection
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_NEEDS_REAUTH = 'needs_reauth';
    public const STATUS_DISABLED = 'disabled';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 255)]
    private string $googleAccountEmail = '';

    /** Encrypted refresh token (sodium secretbox). */
    #[ORM\Column(type: 'text')]
    private string $refreshTokenEnc = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $accessTokenEnc = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $accessTokenExpiresAt = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getGoogleAccountEmail(): string
    {
        return $this->googleAccountEmail;
    }

    public function setGoogleAccountEmail(string $googleAccountEmail): void
    {
        $this->googleAccountEmail = strtolower(trim($googleAccountEmail));
        $this->touch();
    }

    public function getRefreshTokenEnc(): string
    {
        return $this->refreshTokenEnc;
    }

    public function setRefreshTokenEnc(string $refreshTokenEnc): void
    {
        $this->refreshTokenEnc = $refreshTokenEnc;
        $this->touch();
    }

    public function getAccessTokenEnc(): ?string
    {
        return $this->accessTokenEnc;
    }

    public function setAccessTokenEnc(?string $accessTokenEnc): void
    {
        $this->accessTokenEnc = $accessTokenEnc;
        $this->touch();
    }

    public function getAccessTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->accessTokenExpiresAt;
    }

    public function setAccessTokenExpiresAt(?\DateTimeImmutable $accessTokenExpiresAt): void
    {
        $this->accessTokenExpiresAt = $accessTokenExpiresAt;
        $this->touch();
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
        $this->touch();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
