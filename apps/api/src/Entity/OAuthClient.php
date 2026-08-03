<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OAuthClientRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: OAuthClientRepository::class)]
#[ORM\Table(name: 'oauth_clients')]
#[ORM\UniqueConstraint(name: 'uniq_oauth_client_id', columns: ['client_id'])]
class OAuthClient
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 64)]
    private string $clientId = '';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $clientSecretHash = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $clientName = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $redirectUris = [];

    #[ORM\Column(length: 40)]
    private string $tokenEndpointAuthMethod = 'none';

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $grantTypes = ['authorization_code', 'refresh_token'];

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $responseTypes = ['code'];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<string> $redirectUris
     */
    public function __construct(
        string $clientId,
        array $redirectUris,
        ?string $clientName = null,
        string $tokenEndpointAuthMethod = 'none',
        ?string $clientSecretHash = null,
    ) {
        $this->id = Uuid::v7();
        $this->clientId = $clientId;
        $this->redirectUris = array_values($redirectUris);
        $this->clientName = $clientName;
        $this->tokenEndpointAuthMethod = $tokenEndpointAuthMethod;
        $this->clientSecretHash = $clientSecretHash;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function getClientSecretHash(): ?string
    {
        return $this->clientSecretHash;
    }

    public function getClientName(): ?string
    {
        return $this->clientName;
    }

    /**
     * @return list<string>
     */
    public function getRedirectUris(): array
    {
        return $this->redirectUris;
    }

    public function getTokenEndpointAuthMethod(): string
    {
        return $this->tokenEndpointAuthMethod;
    }

    /**
     * @return list<string>
     */
    public function getGrantTypes(): array
    {
        return $this->grantTypes;
    }

    /**
     * @return list<string>
     */
    public function getResponseTypes(): array
    {
        return $this->responseTypes;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isPublicClient(): bool
    {
        return $this->tokenEndpointAuthMethod === 'none' || $this->clientSecretHash === null;
    }
}
