<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TodoGoogleEventLinkRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TodoGoogleEventLinkRepository::class)]
#[ORM\Table(name: 'todo_google_event_links')]
#[ORM\UniqueConstraint(name: 'uniq_todo_gcal_user_dataset_todo', columns: ['user_id', 'dataset_id', 'todo_id'])]
#[ORM\UniqueConstraint(name: 'uniq_todo_gcal_user_cal_event', columns: ['user_id', 'google_calendar_id', 'google_event_id'])]
#[ORM\Index(name: 'idx_todo_gcal_dataset_todo', columns: ['dataset_id', 'todo_id'])]
class TodoGoogleEventLink
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Dataset::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Dataset $dataset;

    #[ORM\Column(length: 64)]
    private string $todoId;

    #[ORM\Column(length: 256)]
    private string $googleCalendarId;

    #[ORM\Column(length: 256)]
    private string $googleEventId;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $etag = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $contentHash = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        User $user,
        Dataset $dataset,
        string $todoId,
        string $googleCalendarId,
        string $googleEventId,
    ) {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->dataset = $dataset;
        $this->todoId = $todoId;
        $this->googleCalendarId = $googleCalendarId;
        $this->googleEventId = $googleEventId;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getDataset(): Dataset
    {
        return $this->dataset;
    }

    public function getTodoId(): string
    {
        return $this->todoId;
    }

    public function getGoogleCalendarId(): string
    {
        return $this->googleCalendarId;
    }

    public function setGoogleCalendarId(string $googleCalendarId): void
    {
        $this->googleCalendarId = $googleCalendarId;
        $this->touch();
    }

    public function getGoogleEventId(): string
    {
        return $this->googleEventId;
    }

    public function setGoogleEventId(string $googleEventId): void
    {
        $this->googleEventId = $googleEventId;
        $this->touch();
    }

    public function getEtag(): ?string
    {
        return $this->etag;
    }

    public function setEtag(?string $etag): void
    {
        $this->etag = $etag;
        $this->touch();
    }

    public function getContentHash(): ?string
    {
        return $this->contentHash;
    }

    public function setContentHash(?string $contentHash): void
    {
        $this->contentHash = $contentHash;
        $this->touch();
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
