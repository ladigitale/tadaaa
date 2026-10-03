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
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
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
        #[Autowire(service: 'limiter.artifacts_intake')]
        private readonly RateLimiterFactoryInterface $artifactsIntakeLimiter,
    ) {
    }

    #[Route('/{slug}/collections/{name}', name: 'api_public_artifact_collection', methods: ['GET'], requirements: ['slug' => '[a-z0-9][a-z0-9-]{2,63}', 'name' => '[a-z][a-z0-9_]{0,63}'])]
    public function collection(string $slug, string $name, Request $request): JsonResponse
    {
        $this->consumeRate($request);
        $user = $this->getUser();
        $readKey = $request->query->getString('rk') ?: null;
        $response = $this->json(['member' => $this->artifactData->readPublic(
            $slug,
            $name,
            $request->query->getString('k') ?: null,
            $readKey,
            $user instanceof User ? $user : null,
        )]);
        // Lecture par lien secret ou membre : jamais en cache partagé.
        $response->headers->set('Cache-Control', $readKey !== null || $user instanceof User ? 'private, no-store' : 'public, max-age=30');

        return $response;
    }

    /**
     * Envoi anonyme dans une collecte contrôlée (schéma + session ouverte + quota).
     * Corps : {"data": {...}, "code": "1234"?}
     */
    #[Route('/{slug}/collections/{name}/records', name: 'api_public_artifact_intake', methods: ['POST'], requirements: ['slug' => '[a-z0-9][a-z0-9-]{2,63}', 'name' => '[a-z][a-z0-9_]{0,63}'])]
    public function intake(string $slug, string $name, Request $request): JsonResponse
    {
        $limit = $this->artifactsIntakeLimiter->create($request->getClientIp() ?: 'anon')->consume(1);
        if (!$limit->isAccepted()) {
            $retry = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException($retry, 'Trop d’envois, réessayez dans quelques secondes.');
        }
        if (\strlen($request->getContent()) > 4096) {
            return $this->json(['error' => 'Envoi trop volumineux.'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        /** @var mixed $body */
        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            return $this->json(['error' => 'JSON attendu.'], Response::HTTP_BAD_REQUEST);
        }
        $code = $body['code'] ?? null;
        $result = $this->artifactData->createIntakeRecord(
            $slug,
            $name,
            $request->query->getString('k') ?: null,
            $body['data'] ?? null,
            \is_string($code) || \is_int($code) ? (string) $code : null,
            $request->getClientIp() ?: 'anon',
        );
        $response = $this->json($result, Response::HTTP_CREATED);
        $response->headers->set('Cache-Control', 'no-store');

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

    #[Route('/{slug}/collections/{name}/records', name: 'api_public_artifact_intake_options', methods: ['OPTIONS'], requirements: ['slug' => '[a-z0-9][a-z0-9-]{2,63}', 'name' => '[a-z][a-z0-9_]{0,63}'])]
    public function intakeOptions(): Response
    {
        return $this->options();
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
