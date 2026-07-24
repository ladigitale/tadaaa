<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\OAuthIssuer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class WellKnownController extends AbstractController
{
    public function __construct(private readonly OAuthIssuer $issuer)
    {
    }

    #[Route('/.well-known/oauth-protected-resource', name: 'well_known_oauth_protected_resource', methods: ['GET'])]
    #[Route('/.well-known/oauth-protected-resource/mcp', name: 'well_known_oauth_protected_resource_mcp', methods: ['GET'])]
    public function protectedResource(): JsonResponse
    {
        return $this->json($this->issuer->protectedResourceMetadata());
    }

    #[Route('/.well-known/oauth-authorization-server', name: 'well_known_oauth_authorization_server', methods: ['GET'])]
    public function authorizationServer(): JsonResponse
    {
        return $this->json($this->issuer->authorizationServerMetadata());
    }
}
