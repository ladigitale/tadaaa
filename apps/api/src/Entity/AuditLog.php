<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuditLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'audit_logs')]
#[ORM\Index(name: 'idx_audit_owner_created', columns: ['owner_id', 'created_at'])]
#[ORM\Index(name: 'idx_audit_category', columns: ['owner_id', 'category'])]
class AuditLog
{
    public const CATEGORY_WEBHOOK = 'webhook';
    public const CATEGORY_MCP = 'mcp';
    public const CATEGORY_TOKEN = 'token';
    public const CATEGORY_OAUTH = 'oauth';
    public const CATEGORY_USAGE = 'usage';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 32)]
    private string $category = '';

    #[ORM\Column(length: 64)]
    private string $action = '';

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $meta = [];

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(User $owner, string $category, string $action, array $meta = [], ?string $ip = null)
    {
        $this->id = Uuid::v7();
        $this->owner = $owner;
        $this->category = $category;
        $this->action = $action;
        $this->meta = $meta;
        $this->ip = $ip;
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

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        return $this->meta;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
