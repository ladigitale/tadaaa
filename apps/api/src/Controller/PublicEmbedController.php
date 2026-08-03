<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\EmbedService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/public/embeds')]
final class PublicEmbedController extends AbstractController
{
    public function __construct(private readonly EmbedService $embeds)
    {
    }

    #[Route('/{token}', name: 'api_public_embed_feed', methods: ['GET'], requirements: ['token' => 'emb_[a-f0-9]+'])]
    public function feed(string $token, Request $request): JsonResponse
    {
        $origin = $request->headers->get('Origin');
        $result = $this->embeds->publicFeed($token, $origin !== '' ? $origin : null);

        $response = $this->json($result['body']);
        $response->headers->set('Cache-Control', 'public, max-age=30');
        $response->headers->set('X-Tadaaa-Embed-Bytes', (string) $result['bytes']);

        return $response;
    }

    #[Route('/{token}', name: 'api_public_embed_options', methods: ['OPTIONS'], requirements: ['token' => 'emb_[a-f0-9]+'])]
    public function options(): Response
    {
        // CORS headers added by EmbedCorsSubscriber.
        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
