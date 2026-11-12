<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ArtifactAssets;
use App\Service\ArtifactRunService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Runs interactifs / intégrés côté viewer : le jeton du run (`rt`) suffit, sans compte.
 * Le viewer lit l'entrée, l'envoie au store, puis publie la sortie quand `io.output.when` est vrai.
 */
#[Route('/api/public/artifacts/{slug}/runs/{runId}', requirements: ['slug' => '[a-z0-9][a-z0-9-]{2,63}', 'runId' => '[0-9a-fA-F-]{36}'])]
final class PublicArtifactRunController extends AbstractController
{
    private const MAX_BODY = 70000;

    public function __construct(
        private readonly ArtifactRunService $runs,
        #[Autowire(service: 'limiter.artifacts_intake')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('', name: 'api_public_artifact_run_get', methods: ['GET'])]
    public function get(string $slug, string $runId, Request $request): JsonResponse
    {
        $this->consumeRate($request);
        $response = $this->json(
            $this->runs->publicGet($slug, $runId, $request->query->getString('rt')),
            200,
            [],
            ['preserve_empty_objects' => true],
        );
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /** Corps : {"rt": "<jeton>", "output": …}. */
    #[Route('/output', name: 'api_public_artifact_run_output', methods: ['POST'])]
    public function output(string $slug, string $runId, Request $request): JsonResponse
    {
        $this->consumeRate($request);
        if (\strlen($request->getContent()) > self::MAX_BODY) {
            return $this->json(['error' => 'Sortie trop volumineuse.'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        // Décodage objet : les {} de la sortie restent des objets.
        $body = json_decode($request->getContent() ?: 'null', false);
        if (!$body instanceof \stdClass || !\is_string($body->rt ?? null) || !property_exists($body, 'output')) {
            return $this->json(['error' => 'JSON attendu : {"rt": "…", "output": …}.'], Response::HTTP_BAD_REQUEST);
        }
        $response = $this->json($this->runs->publicComplete($slug, $runId, $body->rt, $body->output));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /** Fichier de sortie (io.output.files) : corps brut, `?rt=` ; nom `<clé>.<ext>` (png, jpg, webp, svg, webm, wav…). */
    #[Route('/files/{name}', name: 'api_public_artifact_run_file', methods: ['POST'], requirements: ['name' => '[a-z][a-z0-9_-]{0,31}\\.[a-z0-9]{2,5}'])]
    public function uploadFile(string $slug, string $runId, string $name, Request $request): JsonResponse
    {
        $this->consumeRate($request);
        $length = (int) $request->headers->get('Content-Length', '0');
        if ($length > ArtifactAssets::MAX_FILE_BYTES) {
            return $this->json(['error' => 'Fichier trop volumineux.'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        $response = $this->json(
            $this->runs->publicPutFile($slug, $runId, $request->query->getString('rt'), $name, $request->getContent()),
            Response::HTTP_CREATED,
        );
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('', name: 'api_public_artifact_run_options', methods: ['OPTIONS'])]
    #[Route('/output', name: 'api_public_artifact_run_output_options', methods: ['OPTIONS'])]
    #[Route('/files/{name}', name: 'api_public_artifact_run_file_options', methods: ['OPTIONS'])]
    public function options(): Response
    {
        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function consumeRate(Request $request): void
    {
        $limit = $this->limiter->create($request->getClientIp() ?: 'anon')->consume(1);
        if (!$limit->isAccepted()) {
            $retry = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException($retry, 'Trop de requêtes, réessayez dans quelques secondes.');
        }
    }
}
