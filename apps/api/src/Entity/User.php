<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[UniqueEntity(fields: ['email'], message: 'Cet email est déjà utilisé.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $email = '';

    #[ORM\Column]
    private string $password = '';

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column(length: 20, enumType: UserStatus::class)]
    private UserStatus $status = UserStatus::Pending;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, Dataset> */
    #[ORM\OneToMany(mappedBy: 'owner', targetEntity: Dataset::class, orphanRemoval: true)]
    private Collection $datasets;

    #[ORM\OneToOne(targetEntity: Dataset::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Dataset $activeDataset = null;

    /**
     * Account-level link detectors: tokens in todo text/description become links.
     *
     * @var list<array{id: string, name: string, pattern: string, urlTemplate: string}>
     */
    #[ORM\Column(type: 'json')]
    private array $linkDetectors = [];

    /**
     * Opt-in/out map for push event types (missing keys → catalogue defaults).
     *
     * @var array<string, bool>
     */
    #[ORM\Column(type: 'json')]
    private array $notificationPrefs = [];

    /** SHA-256 hex of the raw verify token (or null). */
    #[ORM\Column(length: 64, nullable: true, unique: true)]
    private ?string $emailVerifyToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailVerifyExpiresAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    /**
     * null = env default; 0 = unlimited; >0 = custom byte cap.
     */
    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?string $storageQuotaBytes = null;

    /**
     * null = dynamic default; 0 = unlimited; >0 = custom monthly transfer cap.
     */
    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?string $bandwidthQuotaMonthBytes = null;

    public function __construct(string $email = '', UserStatus $status = UserStatus::Pending)
    {
        $this->id = Uuid::v7();
        $this->email = $email;
        $this->status = $status;
        $this->createdAt = new \DateTimeImmutable();
        $this->datasets = new ArrayCollection();
        $this->linkDetectors = [];
        $this->notificationPrefs = [];
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = strtolower(trim($email));

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getStatus(): UserStatus
    {
        return $this->status;
    }

    public function setStatus(UserStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function eraseCredentials(): void
    {
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, Dataset> */
    #[Ignore]
    public function getDatasets(): Collection
    {
        return $this->datasets;
    }

    public function addDataset(Dataset $dataset): static
    {
        if (!$this->datasets->contains($dataset)) {
            $this->datasets->add($dataset);
            $dataset->setOwner($this);
        }

        return $this;
    }

    public function getActiveDataset(): ?Dataset
    {
        return $this->activeDataset;
    }

    public function setActiveDataset(?Dataset $activeDataset): static
    {
        $this->activeDataset = $activeDataset;

        return $this;
    }

    /**
     * @return list<array{id: string, name: string, pattern: string, urlTemplate: string}>
     */
    public function getLinkDetectors(): array
    {
        return $this->linkDetectors;
    }

    /**
     * @param list<array{id: string, name: string, pattern: string, urlTemplate: string}> $linkDetectors
     */
    public function setLinkDetectors(array $linkDetectors): static
    {
        $this->linkDetectors = array_values($linkDetectors);

        return $this;
    }

    /**
     * @return array<string, bool>
     */
    public function getNotificationPrefs(): array
    {
        return $this->notificationPrefs;
    }

    /**
     * @param array<string, bool> $notificationPrefs
     */
    public function setNotificationPrefs(array $notificationPrefs): static
    {
        $this->notificationPrefs = $notificationPrefs;

        return $this;
    }

    public function getEmailVerifyToken(): ?string
    {
        return $this->emailVerifyToken;
    }

    public function setEmailVerifyToken(?string $emailVerifyToken): static
    {
        $this->emailVerifyToken = $emailVerifyToken;

        return $this;
    }

    public function getEmailVerifyExpiresAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifyExpiresAt;
    }

    public function setEmailVerifyExpiresAt(?\DateTimeImmutable $emailVerifyExpiresAt): static
    {
        $this->emailVerifyExpiresAt = $emailVerifyExpiresAt;

        return $this;
    }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function setEmailVerifiedAt(?\DateTimeImmutable $emailVerifiedAt): static
    {
        $this->emailVerifiedAt = $emailVerifiedAt;

        return $this;
    }

    public function clearEmailVerificationChallenge(): static
    {
        $this->emailVerifyToken = null;
        $this->emailVerifyExpiresAt = null;

        return $this;
    }

    public function getStorageQuotaBytes(): ?int
    {
        return $this->storageQuotaBytes === null ? null : (int) $this->storageQuotaBytes;
    }

    public function setStorageQuotaBytes(?int $storageQuotaBytes): static
    {
        $this->storageQuotaBytes = $storageQuotaBytes === null ? null : (string) $storageQuotaBytes;

        return $this;
    }

    public function getBandwidthQuotaMonthBytes(): ?int
    {
        return $this->bandwidthQuotaMonthBytes === null ? null : (int) $this->bandwidthQuotaMonthBytes;
    }

    public function setBandwidthQuotaMonthBytes(?int $bandwidthQuotaMonthBytes): static
    {
        $this->bandwidthQuotaMonthBytes = $bandwidthQuotaMonthBytes === null
            ? null
            : (string) $bandwidthQuotaMonthBytes;

        return $this;
    }
}
