<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GoogleCalendarBindingRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: GoogleCalendarBindingRepository::class)]
#[ORM\Table(name: 'google_calendar_bindings')]
#[ORM\UniqueConstraint(name: 'uniq_gcal_binding_user_dataset_cal', columns: ['user_id', 'dataset_id', 'google_calendar_id'])]
#[ORM\Index(name: 'idx_gcal_binding_user_dataset', columns: ['user_id', 'dataset_id'])]
#[ORM\Index(name: 'idx_gcal_binding_watch', columns: ['watch_channel_id'])]
class GoogleCalendarBinding
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

    #[ORM\Column(length: 256)]
    private string $googleCalendarId = '';

    #[ORM\Column(length: 255)]
    private string $googleCalendarSummary = '';

    /**
     * Tag ids from this dataset — OR match for export routing.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $tagIds = [];

    #[ORM\Column]
    private bool $isDefault = false;

    #[ORM\Column]
    private bool $exportEnabled = true;

    #[ORM\Column]
    private bool $importEnabled = true;

    /** Higher wins when several bindings OR-match the same todo. */
    #[ORM\Column]
    private int $priority = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $syncToken = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $watchChannelId = null;

    #[ORM\Column(length: 256, nullable: true)]
    private ?string $watchResourceId = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $watchExpiresAt = null;

    /** Shared secret echoed by Google push (X-Goog-Channel-Token). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $watchToken = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param list<string> $tagIds
     */
    public function __construct(
        User $user,
        Dataset $dataset,
        string $googleCalendarId,
        string $googleCalendarSummary = '',
        array $tagIds = [],
        bool $isDefault = false,
        int $priority = 0,
    ) {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->dataset = $dataset;
        $this->googleCalendarId = $googleCalendarId;
        $this->googleCalendarSummary = $googleCalendarSummary;
        $this->tagIds = array_values(array_unique($tagIds));
        $this->isDefault = $isDefault;
        $this->priority = $priority;
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

    public function getDataset(): Dataset
    {
        return $this->dataset;
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

    public function getGoogleCalendarSummary(): string
    {
        return $this->googleCalendarSummary;
    }

    public function setGoogleCalendarSummary(string $googleCalendarSummary): void
    {
        $this->googleCalendarSummary = $googleCalendarSummary;
        $this->touch();
    }

    /** @return list<string> */
    public function getTagIds(): array
    {
        return $this->tagIds;
    }

    /** @param list<string> $tagIds */
    public function setTagIds(array $tagIds): void
    {
        $this->tagIds = array_values(array_unique(array_values($tagIds)));
        $this->touch();
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault): void
    {
        $this->isDefault = $isDefault;
        $this->touch();
    }

    public function isExportEnabled(): bool
    {
        return $this->exportEnabled;
    }

    public function setExportEnabled(bool $exportEnabled): void
    {
        $this->exportEnabled = $exportEnabled;
        $this->touch();
    }

    public function isImportEnabled(): bool
    {
        return $this->importEnabled;
    }

    public function setImportEnabled(bool $importEnabled): void
    {
        $this->importEnabled = $importEnabled;
        $this->touch();
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): void
    {
        $this->priority = $priority;
        $this->touch();
    }

    public function getSyncToken(): ?string
    {
        return $this->syncToken;
    }

    public function setSyncToken(?string $syncToken): void
    {
        $this->syncToken = $syncToken;
        $this->touch();
    }

    public function getWatchChannelId(): ?string
    {
        return $this->watchChannelId;
    }

    public function setWatchChannelId(?string $watchChannelId): void
    {
        $this->watchChannelId = $watchChannelId;
        $this->touch();
    }

    public function getWatchResourceId(): ?string
    {
        return $this->watchResourceId;
    }

    public function setWatchResourceId(?string $watchResourceId): void
    {
        $this->watchResourceId = $watchResourceId;
        $this->touch();
    }

    public function getWatchExpiresAt(): ?\DateTimeImmutable
    {
        return $this->watchExpiresAt;
    }

    public function setWatchExpiresAt(?\DateTimeImmutable $watchExpiresAt): void
    {
        $this->watchExpiresAt = $watchExpiresAt;
        $this->touch();
    }

    public function getWatchToken(): ?string
    {
        return $this->watchToken;
    }

    public function setWatchToken(?string $watchToken): void
    {
        $this->watchToken = $watchToken;
        $this->touch();
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

    /** OR match: any shared tag id. */
    public function matchesTodoTags(array $todoTagIds): bool
    {
        if ($this->tagIds === []) {
            return false;
        }
        $set = array_flip($todoTagIds);
        foreach ($this->tagIds as $tagId) {
            if (isset($set[$tagId])) {
                return true;
            }
        }

        return false;
    }
}
