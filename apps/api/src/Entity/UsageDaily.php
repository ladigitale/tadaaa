<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UsageDailyRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UsageDailyRepository::class)]
#[ORM\Table(name: 'usage_daily')]
#[ORM\UniqueConstraint(name: 'uniq_usage_owner_day_dataset', columns: ['owner_id', 'day', 'dataset_key'])]
class UsageDaily
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $day;

    #[ORM\ManyToOne(targetEntity: Dataset::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Dataset $dataset = null;

    /** Clé de dédoublonnage : UUID dataset ou « _ » pour compte. */
    #[ORM\Column(length: 40)]
    private string $datasetKey = '_';

    /**
     * @var array<string, int>
     */
    #[ORM\Column(type: 'json')]
    private array $counters = [];

    public function __construct(User $owner, \DateTimeImmutable $day, ?Dataset $dataset = null)
    {
        $this->id = Uuid::v7();
        $this->owner = $owner;
        $this->day = $day->setTime(0, 0);
        $this->dataset = $dataset;
        $this->datasetKey = $dataset?->getId()->toRfc4122() ?? '_';
        $this->counters = [];
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getDay(): \DateTimeImmutable
    {
        return $this->day;
    }

    public function getDataset(): ?Dataset
    {
        return $this->dataset;
    }

    public function getDatasetKey(): string
    {
        return $this->datasetKey;
    }

    /**
     * @return array<string, int>
     */
    public function getCounters(): array
    {
        return $this->counters;
    }

    public function increment(string $key, int $n = 1): void
    {
        $this->counters[$key] = ($this->counters[$key] ?? 0) + $n;
    }
}
