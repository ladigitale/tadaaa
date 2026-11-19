<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ArtifactRunPairingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Appairage (io.stream.pair) : code demandé par le viewer d'un run hôte, puis réclamé par l'appareil
 * qui ouvre l'artefact `remote` avec `?pair=<code>`.
 */
#[Route('/api/public/artifacts/{slug}', requirements: ['slug' => '[a-z0-9][a-z0-9-]{2,63}'])]
final class PublicArtifactPairController extends AbstractController
{
    public function __construct(
        private readonly ArtifactRunPairingService $pairing,
        #[Autowire(service: 'limiter.artifacts_stream')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    /** Code d'appairage d'un run hôte : {"rt": "<jeton du run>"}. */
    #[Route('/runs/{runId}/pair', name: 'api_public_artifact_run_pair', requirements: ['runId' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function issue(string $slug, string $runId, Request $request): JsonResponse
    {
        $this->consume($request);
        $body = json_decode($request->getContent() ?: 'null', true);
        $rt = \is_array($body) && \is_string($body['rt'] ?? null) ? $body['rt'] : '';

        return $this->noStore($this->json($this->pairing->issue($slug, $runId, $rt)));
    }

    /** Réclamer un code : lance le run de cet artefact, relié à l'écran qui a montré le code. */
    #[Route('/pair/{code}', name: 'api_public_artifact_pair_claim', requirements: ['code' => '[A-Za-z0-9]{4,16}'], methods: ['POST'])]
    public function claim(string $slug, string $code, Request $request): JsonResponse
    {
        $this->consume($request);

        return $this->noStore($this->json($this->pairing->claim($slug, $code), Response::HTTP_CREATED));
    }

    #[Route('/runs/{runId}/pair', name: 'api_public_artifact_run_pair_options', requirements: ['runId' => '[0-9a-fA-F-]{36}'], methods: ['OPTIONS'])]
    #[Route('/pair/{code}', name: 'api_public_artifact_pair_claim_options', requirements: ['code' => '[A-Za-z0-9]{4,16}'], methods: ['OPTIONS'])]
    public function options(): Response
    {
        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private function consume(Request $request): void
    {
        $limit = $this->limiter->create($request->getClientIp() ?: 'anon')->consume(1);
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Trop de requêtes, réessayez dans quelques secondes.');
        }
    }

    private function noStore(JsonResponse $r): JsonResponse
    {
        $r->headers->set('Cache-Control', 'no-store');

        return $r;
    }
}
