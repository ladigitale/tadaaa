<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DatasetAppDocumentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Generic JSON document scoped to a dataset + app id (e.g. "belts").
 * Not part of the todo/tag sync model.
 */
#[ORM\Entity(repositoryClass: DatasetAppDocumentRepository::class)]
#[ORM\Table(name: 'dataset_app_documents')]
#[ORM\UniqueConstraint(name: 'uniq_dataset_app', columns: ['dataset_id', 'app_id'])]
class DatasetAppDocument
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Dataset::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Dataset $dataset;

    #[ORM\Column(length: 64)]
    private string $appId;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $payload = [];

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Dataset $dataset, string $appId)
    {
        $this->id = Uuid::v7();
        $this->dataset = $dataset;
        $this->appId = $appId;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDataset(): Dataset
    {
        return $this->dataset;
    }

    public function getAppId(): string
    {
        return $this->appId;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /** @param array<string, mixed> $payload */
    public function setPayload(array $payload): static
    {
        $this->payload = $payload;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
