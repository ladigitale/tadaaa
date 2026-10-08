<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AgentSettingsRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Réglages de l'assistant IA d'un utilisateur : fournisseur, modèle, clé API (chiffrée)
 * et, en option, une URL de base libre. Un enregistrement par utilisateur.
 */
#[ORM\Entity(repositoryClass: AgentSettingsRepository::class)]
#[ORM\Table(name: 'agent_settings')]
class AgentSettings
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 32)]
    private string $provider = '';

    #[ORM\Column(length: 128)]
    private string $model = '';

    /** Clé API chiffrée (`v1:` + base64(nonce ‖ texte chiffré)), jamais exposée. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $apiKeyCipher = null;

    /** 4 derniers caractères de la clé, pour l'affichage. */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $keyHint = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $customBaseUrlEnabled = false;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $customBaseUrl = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): void
    {
        $this->provider = $provider;
        $this->touch();
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function setModel(string $model): void
    {
        $this->model = $model;
        $this->touch();
    }

    public function getApiKeyCipher(): ?string
    {
        return $this->apiKeyCipher;
    }

    public function getKeyHint(): ?string
    {
        return $this->keyHint;
    }

    public function hasKey(): bool
    {
        return $this->apiKeyCipher !== null;
    }

    public function setApiKey(?string $cipher, ?string $hint): void
    {
        $this->apiKeyCipher = $cipher;
        $this->keyHint = $hint;
        $this->touch();
    }

    public function isCustomBaseUrlEnabled(): bool
    {
        return $this->customBaseUrlEnabled;
    }

    public function getCustomBaseUrl(): ?string
    {
        return $this->customBaseUrl;
    }

    public function setCustomBaseUrl(bool $enabled, ?string $url): void
    {
        $this->customBaseUrlEnabled = $enabled;
        $this->customBaseUrl = $url;
        $this->touch();
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
