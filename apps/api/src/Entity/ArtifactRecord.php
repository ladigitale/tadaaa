<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ArtifactRecordRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ArtifactRecordRepository::class)]
#[ORM\Table(name: 'artifact_records')]
#[ORM\Index(name: 'idx_artifact_record_collection', columns: ['collection_id'])]
#[ORM\Index(name: 'idx_artifact_record_owner', columns: ['created_by_id'])]
class ArtifactRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ArtifactCollection::class, inversedBy: 'records')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ArtifactCollection $collection;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $data = [];

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @param array<string, mixed> $data */
    public function __construct(ArtifactCollection $collection, array $data, ?User $createdBy = null)
    {
        $this->id = Uuid::v7();
        $this->collection = $collection;
        $this->data = $data;
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCollection(): ArtifactCollection
    {
        return $this->collection;
    }

    /** @return array<string, mixed> */
    public function getData(): array
    {
        return $this->data;
    }

    /** @param array<string, mixed> $data */
    public function setData(array $data): static
    {
        $this->data = $data;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
