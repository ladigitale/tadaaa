<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Issuer / MCP resource URLs for OAuth discovery (same host as /mcp).
 */
final class OAuthIssuer
{
    public function __construct(
        #[Autowire('%env(DEFAULT_URI)%')]
        private readonly string $defaultUri,
    ) {
    }

    public function issuer(): string
    {
        return rtrim($this->defaultUri, '/');
    }

    public function mcpResource(): string
    {
        return $this->issuer().'/mcp';
    }

    public function protectedResourceMetadataUrl(): string
    {
        return $this->issuer().'/.well-known/oauth-protected-resource';
    }

    public function authorizationServerMetadataUrl(): string
    {
        return $this->issuer().'/.well-known/oauth-authorization-server';
    }

    public function authorizationEndpoint(): string
    {
        return $this->issuer().'/oauth/authorize';
    }

    public function tokenEndpoint(): string
    {
        return $this->issuer().'/oauth/token';
    }

    public function registrationEndpoint(): string
    {
        return $this->issuer().'/oauth/register';
    }

    public function revocationEndpoint(): string
    {
        return $this->issuer().'/oauth/revoke';
    }

    /**
     * @return array{resource: string, authorization_servers: list<string>, scopes_supported: list<string>, bearer_methods_supported: list<string>}
     */
    public function protectedResourceMetadata(): array
    {
        return [
            'resource' => $this->mcpResource(),
            'authorization_servers' => [$this->issuer()],
            'scopes_supported' => ['mcp', 'offline_access'],
            'bearer_methods_supported' => ['header'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function authorizationServerMetadata(): array
    {
        return [
            'issuer' => $this->issuer(),
            'authorization_endpoint' => $this->authorizationEndpoint(),
            'token_endpoint' => $this->tokenEndpoint(),
            'registration_endpoint' => $this->registrationEndpoint(),
            'revocation_endpoint' => $this->revocationEndpoint(),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post'],
            'scopes_supported' => ['mcp', 'offline_access'],
            'authorization_response_iss_parameter_supported' => true,
        ];
    }

    public function wwwAuthenticateHeader(): string
    {
        $meta = $this->protectedResourceMetadataUrl();

        return sprintf(
            'Bearer FAKESECRET_g3h4i5j6k7l8m9n0o1p2="%s", scope="mcp"',
            $meta,
        );
    }
}
