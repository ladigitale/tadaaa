<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TagRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TagRepository::class)]
#[ORM\Table(name: 'tags')]
#[ORM\UniqueConstraint(name: 'uniq_tag_dataset_id', columns: ['dataset_id', 'id'])]
class Tag
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Dataset::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Dataset $dataset;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column(length: 32)]
    private string $color = 'default';

    /** @var array<string, string> */
    #[ORM\Column(type: 'json')]
    private array $fieldVersions = [];

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct(string $id, Dataset $dataset)
    {
        $this->id = $id;
        $this->dataset = $dataset;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getDataset(): Dataset
    {
        return $this->dataset;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);

        return $this;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    /** @return array<string, string> */
    public function getFieldVersions(): array
    {
        return $this->fieldVersions;
    }

    /** @param array<string, string> $fieldVersions */
    public function setFieldVersions(array $fieldVersions): static
    {
        $this->fieldVersions = $fieldVersions;

        return $this;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?\DateTimeImmutable $deletedAt): static
    {
        $this->deletedAt = $deletedAt;

        return $this;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    /** @return array<string, mixed> */
    public function toSyncArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'color' => $this->color,
            'fieldVersions' => $this->fieldVersions,
            'deletedAt' => $this->deletedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
