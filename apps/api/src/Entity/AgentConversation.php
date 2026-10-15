<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AgentConversationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Conversation de l'assistant IA d'un utilisateur, gardée pour l'historique et la reprise :
 * journal compact (réaffichage dans le chat), dernier aperçu / publication, et mémoire
 * complète de l'agent (transcription avec appels d'outils, compressée).
 */
#[ORM\Entity(repositoryClass: AgentConversationRepository::class)]
#[ORM\Table(name: 'agent_conversations')]
#[ORM\UniqueConstraint(name: 'UNIQ_AGENT_CONVERSATION_THREAD', columns: ['user_id', 'profile', 'thread_id'])]
#[ORM\Index(name: 'IDX_AGENT_CONVERSATION_LIST', columns: ['user_id', 'profile', 'updated_at'])]
class AgentConversation
{
    public const TITLE_MAX = 120;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 32)]
    private string $profile;

    #[ORM\Column(length: 100)]
    private string $threadId;

    #[ORM\Column(length: 120)]
    private string $title = '';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $artifactSlug = null;

    /** Journal compact des échanges (JSON), voir RecordingEventSink. */
    #[ORM\Column(type: 'text')]
    private string $transcript = '[]';

    /** Dernier aperçu et dernière publication : `{preview?, published?}`. */
    #[ORM\Column(type: 'json')]
    private array $meta = [];

    /** ThreadState sérialisé, compressé, en base64 : mémoire de l'agent après expiration du cache. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $state = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, string $profile, string $threadId)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->profile = $profile;
        $this->threadId = $threadId;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getProfile(): string
    {
        return $this->profile;
    }

    public function getThreadId(): string
    {
        return $this->threadId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = mb_substr(trim($title), 0, self::TITLE_MAX);
    }

    public function getArtifactSlug(): ?string
    {
        return $this->artifactSlug;
    }

    public function setArtifactSlug(?string $slug): void
    {
        $this->artifactSlug = $slug;
    }

    public function getTranscript(): string
    {
        return $this->transcript;
    }

    public function setTranscript(string $json): void
    {
        $this->transcript = $json;
    }

    /** @return array<string, mixed> */
    public function getMeta(): array
    {
        return $this->meta;
    }

    /** @param array<string, mixed> $meta */
    public function setMeta(array $meta): void
    {
        $this->meta = $meta;
    }

    public function getState(): ?string
    {
        return $this->state;
    }

    public function setState(?string $state): void
    {
        $this->state = $state;
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
}
