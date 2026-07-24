<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DatasetMemberRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DatasetMemberRepository::class)]
#[ORM\Table(name: 'dataset_members')]
#[ORM\UniqueConstraint(name: 'uniq_dataset_member_user', columns: ['dataset_id', 'user_id'])]
class DatasetMember
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Dataset::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Dataset $dataset;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20, enumType: DatasetMemberRole::class)]
    private DatasetMemberRole $role;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Dataset $dataset, User $user, DatasetMemberRole $role)
    {
        $this->id = Uuid::v7();
        $this->dataset = $dataset;
        $this->user = $user;
        $this->role = $role;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDataset(): Dataset
    {
        return $this->dataset;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRole(): DatasetMemberRole
    {
        return $this->role;
    }

    public function setRole(DatasetMemberRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
