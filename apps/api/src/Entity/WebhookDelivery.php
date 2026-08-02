<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WebhookDeliveryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: WebhookDeliveryRepository::class)]
#[ORM\Table(name: 'webhook_deliveries')]
#[ORM\Index(name: 'idx_webhook_delivery_endpoint', columns: ['endpoint_id', 'created_at'])]
class WebhookDelivery
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: WebhookEndpoint::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private WebhookEndpoint $endpoint;

    #[ORM\Column(length: 64)]
    private string $eventId = '';

    #[ORM\Column(length: 64)]
    private string $eventType = '';

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_FAILED;

    #[ORM\Column(nullable: true)]
    private ?int $httpStatus = null;

    #[ORM\Column(nullable: true)]
    private ?int $responseMs = null;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $error = null;

    #[ORM\Column]
    private int $requestBytes = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        WebhookEndpoint $endpoint,
        string $eventId,
        string $eventType,
        string $status,
        int $requestBytes,
        ?int $httpStatus = null,
        ?int $responseMs = null,
        ?string $error = null,
    ) {
        $this->id = Uuid::v7();
        $this->endpoint = $endpoint;
        $this->eventId = $eventId;
        $this->eventType = $eventType;
        $this->status = $status;
        $this->requestBytes = $requestBytes;
        $this->httpStatus = $httpStatus;
        $this->responseMs = $responseMs;
        $this->error = $error;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEndpoint(): WebhookEndpoint
    {
        return $this->endpoint;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function getResponseMs(): ?int
    {
        return $this->responseMs;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getRequestBytes(): int
    {
        return $this->requestBytes;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
