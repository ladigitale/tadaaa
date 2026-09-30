<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\ArtifactDataService;
use App\Service\ArtifactService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/public/artifacts')]
final class PublicArtifactController extends AbstractController
{
    public function __construct(
        private readonly ArtifactService $artifacts,
        private readonly ArtifactDataService $artifactData,
        #[Autowire(service: 'limiter.artifacts_public')]
        private readonly RateLimiterFactoryInterface $artifactsPublicLimiter,
    ) {
    }

    #[Route('/{slug}/collections/{name}', name: 'api_public_artifact_collection', methods: ['GET'], requirements: ['slug' => '[a-z0-9][a-z0-9-]{2,63}', 'name' => '[a-z][a-z0-9_]{0,63}'])]
    public function collection(string $slug, string $name, Request $request): JsonResponse
    {
        $this->consumeRate($request);
        $response = $this->json(['member' => $this->artifactData->readPublic($slug, $name)]);
        $response->headers->set('Cache-Control', 'public, max-age=30');

        return $response;
    }

    #[Route('/{slug}', name: 'api_public_artifact_get', methods: ['GET'], requirements: ['slug' => '[a-z0-9][a-z0-9-]{2,63}'])]
    public function get(string $slug, Request $request): JsonResponse
    {
        $this->consumeRate($request);
        $user = $this->getUser();
        $result = $this->artifacts->publicRead(
            $slug,
            $request->query->getString('k') ?: null,
            $user instanceof User ? $user : null,
        );

        $response = $this->json($result['body']);
        $response->setEtag($result['etag']);
        if ($response->isNotModified($request)) {
            return $response;
        }
        $response->headers->set('Cache-Control', 'public, max-age=30');
        $response->headers->set('ETag', $result['etag']);

        return $response;
    }

    #[Route('/{slug}/meta', name: 'api_public_artifact_meta', methods: ['GET'], requirements: ['slug' => '[a-z0-9][a-z0-9-]{2,63}'])]
    public function meta(string $slug, Request $request): JsonResponse
    {
        $this->consumeRate($request);
        $user = $this->getUser();
        $body = $this->artifacts->publicMeta(
            $slug,
            $request->query->getString('k') ?: null,
            $user instanceof User ? $user : null,
        );

        $response = $this->json($body);
        $response->headers->set('Cache-Control', 'public, max-age=60');

        return $response;
    }

    #[Route('/{slug}', name: 'api_public_artifact_options', methods: ['OPTIONS'], requirements: ['slug' => '[a-z0-9][a-z0-9-]{2,63}'])]
    public function options(): Response
    {
        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function consumeRate(Request $request): void
    {
        $limiter = $this->artifactsPublicLimiter->create($request->getClientIp() ?: 'anon');
        if (!$limiter->consume(1)->isAccepted()) {
            throw $this->createAccessDeniedException('Trop de requêtes.');
        }
    }
}
