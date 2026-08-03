<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WebhookEndpointRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: WebhookEndpointRepository::class)]
#[ORM\Table(name: 'webhook_endpoints')]
#[ORM\Index(name: 'idx_webhook_owner', columns: ['owner_id'])]
class WebhookEndpoint
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    /** Scope optionnel : null = tous les datasets accessibles en écriture. */
    #[ORM\ManyToOne(targetEntity: Dataset::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Dataset $dataset = null;

    #[ORM\Column(length: 2048)]
    private string $url = '';

    /** Secret HMAC (jamais exposé après création). */
    #[ORM\Column(length: 128)]
    private string $signingSecret = '';

    #[ORM\Column(length: 16)]
    private string $secretPrefix = '';

    /**
     * Liste vide = tous les événements du catalogue.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $events = [];

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastDeliveryAt = null;

    #[ORM\Column]
    private int $failureCount = 0;

    /**
     * @param list<string> $events
     */
    public function __construct(
        User $owner,
        string $url,
        string $signingSecret,
        string $secretPrefix,
        array $events = [],
        ?Dataset $dataset = null,
    ) {
        $this->id = Uuid::v7();
        $this->owner = $owner;
        $this->url = $url;
        $this->signingSecret = $signingSecret;
        $this->secretPrefix = $secretPrefix;
        $this->events = $events;
        $this->dataset = $dataset;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getDataset(): ?Dataset
    {
        return $this->dataset;
    }

    public function setDataset(?Dataset $dataset): void
    {
        $this->dataset = $dataset;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): void
    {
        $this->url = $url;
    }

    public function getSigningSecret(): string
    {
        return $this->signingSecret;
    }

    public function getSecretPrefix(): string
    {
        return $this->secretPrefix;
    }

    /**
     * @return list<string>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * @param list<string> $events
     */
    public function setEvents(array $events): void
    {
        $this->events = $events;
    }

    public function acceptsEvent(string $type): bool
    {
        if ($this->events === []) {
            return true;
        }

        return \in_array($type, $this->events, true);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastDeliveryAt(): ?\DateTimeImmutable
    {
        return $this->lastDeliveryAt;
    }

    public function getFailureCount(): int
    {
        return $this->failureCount;
    }

    public function markDeliverySuccess(): void
    {
        $this->lastDeliveryAt = new \DateTimeImmutable();
        $this->failureCount = 0;
    }

    public function markDeliveryFailure(): void
    {
        $this->lastDeliveryAt = new \DateTimeImmutable();
        ++$this->failureCount;
    }
}
