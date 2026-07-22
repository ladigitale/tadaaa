<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TodoRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TodoRepository::class)]
#[ORM\Table(name: 'todos')]
#[ORM\UniqueConstraint(name: 'uniq_todo_dataset_id', columns: ['dataset_id', 'id'])]
class Todo
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Dataset::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Dataset $dataset;

    #[ORM\Column(length: 500)]
    private string $text = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private bool $done = false;

    #[ORM\Column]
    private bool $archived = false;

    #[ORM\Column(length: 16)]
    private string $priority = 'medium';

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $tagIds = [];

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $parentId = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var array<string, string> */
    #[ORM\Column(type: 'json')]
    private array $fieldVersions = [];

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct(string $id, Dataset $dataset)
    {
        $this->id = $id;
        $this->dataset = $dataset;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getDataset(): Dataset
    {
        return $this->dataset;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function setText(string $text): static
    {
        $this->text = trim($text);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $trimmed = $description !== null ? trim($description) : '';
        $this->description = $trimmed !== '' ? $trimmed : null;

        return $this;
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    public function setDone(bool $done): static
    {
        $this->done = $done;

        return $this;
    }

    public function isArchived(): bool
    {
        return $this->archived;
    }

    public function setArchived(bool $archived): static
    {
        $this->archived = $archived;

        return $this;
    }

    public function getPriority(): string
    {
        return $this->priority;
    }

    public function setPriority(string $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    /** @return list<string> */
    public function getTagIds(): array
    {
        return $this->tagIds;
    }

    /** @param list<string> $tagIds */
    public function setTagIds(array $tagIds): static
    {
        $this->tagIds = array_values(array_unique(array_values($tagIds)));

        return $this;
    }

    public function getParentId(): ?string
    {
        return $this->parentId;
    }

    public function setParentId(?string $parentId): static
    {
        $this->parentId = $parentId;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

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
            'text' => $this->text,
            'description' => $this->description,
            'done' => $this->done,
            'archived' => $this->archived,
            'priority' => $this->priority,
            'tagIds' => $this->tagIds,
            'parentId' => $this->parentId,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'fieldVersions' => $this->fieldVersions,
            'deletedAt' => $this->deletedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
