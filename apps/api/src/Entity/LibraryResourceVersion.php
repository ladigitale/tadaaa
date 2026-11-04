<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Version précédente d'une {@see LibraryResource} (mapping seul, accès DBAL par {@see \App\Service\Resources}).
 */
#[ORM\Entity]
#[ORM\Table(name: 'resource_versions')]
class LibraryResourceVersion
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: LibraryResource::class)]
    #[ORM\JoinColumn(name: 'resource_id', nullable: false, onDelete: 'CASCADE')]
    private LibraryResource $resource;

    #[ORM\Id]
    #[ORM\Column]
    private int $version;

    #[ORM\Column(length: 100)]
    private string $contentType;

    #[ORM\Column(type: 'bigint')]
    private string $sizeBytes = '0';

    #[ORM\Column(length: 64)]
    private string $sha256;

    #[ORM\Column(type: 'text')]
    private string $prompt = '';

    /** @var resource|string */
    #[ORM\Column(type: 'blob')]
    private $data;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(LibraryResource $resource, int $version, string $contentType, string $sha256)
    {
        $this->resource = $resource;
        $this->version = $version;
        $this->contentType = $contentType;
        $this->sha256 = $sha256;
        $this->data = '';
        $this->createdAt = new \DateTimeImmutable();
    }
}
