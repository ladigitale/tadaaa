<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DatasetInviteRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DatasetInviteRepository::class)]
#[ORM\Table(name: 'dataset_invites')]
#[ORM\UniqueConstraint(name: 'uniq_dataset_invite_token', columns: ['token'])]
class DatasetInvite
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Dataset::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Dataset $dataset;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $createdBy;

    #[ORM\Column(length: 64)]
    private string $token;

    #[ORM\Column(length: 20, enumType: DatasetMemberRole::class)]
    private DatasetMemberRole $role;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $acceptedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(
        Dataset $dataset,
        User $createdBy,
        string $token,
        DatasetMemberRole $role,
        \DateTimeImmutable $expiresAt,
    ) {
        $this->id = Uuid::v7();
        $this->dataset = $dataset;
        $this->createdBy = $createdBy;
        $this->token = $token;
        $this->role = $role;
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $expiresAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDataset(): Dataset
    {
        return $this->dataset;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getRole(): DatasetMemberRole
    {
        return $this->role;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function getAcceptedBy(): ?User
    {
        return $this->acceptedBy;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function isAccepted(): bool
    {
        return $this->acceptedAt !== null;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();

        return $this->expiresAt <= $now;
    }

    public function isUsable(?\DateTimeImmutable $now = null): bool
    {
        return !$this->isRevoked() && !$this->isAccepted() && !$this->isExpired($now);
    }

    public function accept(User $user): void
    {
        $this->acceptedAt = new \DateTimeImmutable();
        $this->acceptedBy = $user;
    }

    public function revoke(): void
    {
        $this->revokedAt = new \DateTimeImmutable();
    }
}
