<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\Repository\DatasetRepository;
use App\State\DatasetActivateProcessor;
use App\State\DatasetProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DatasetRepository::class)]
#[ORM\Table(name: 'datasets')]
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['dataset:read']],
        ),
        new Post(
            denormalizationContext: ['groups' => ['dataset:write']],
            processor: DatasetProcessor::class,
        ),
        new Get(
            normalizationContext: ['groups' => ['dataset:read']],
        ),
        new Patch(
            denormalizationContext: ['groups' => ['dataset:write']],
            processor: DatasetProcessor::class,
        ),
        new Delete(
            processor: DatasetProcessor::class,
        ),
        new Post(
            uriTemplate: '/datasets/{id}/activate',
            openapi: new Operation(summary: 'Activer ce jeu de données'),
            read: true,
            deserialize: false,
            processor: DatasetActivateProcessor::class,
            normalizationContext: ['groups' => ['dataset:read']],
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class Dataset
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['dataset:read'])]
    private Uuid $id;

    /** Identifiant stable pour export / sync future. */
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['dataset:read'])]
    private Uuid $baseId;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    #[Groups(['dataset:read', 'dataset:write'])]
    private string $name = '';

    #[ORM\Column]
    #[Groups(['dataset:read'])]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'datasets')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    public function __construct(string $name = '')
    {
        $this->id = Uuid::v7();
        $this->baseId = Uuid::v7();
        $this->name = $name;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getBaseId(): Uuid
    {
        return $this->baseId;
    }

    public function setBaseId(Uuid $baseId): static
    {
        $this->baseId = $baseId;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);
        $this->touch();

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    #[Ignore]
    public function getOwner(): User
    {
        return $this->owner;
    }

    public function setOwner(User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }
}
