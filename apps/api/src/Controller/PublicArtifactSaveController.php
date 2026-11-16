<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\ArtifactSaveService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sauvegarde en ligne de l'état d'un artefact (`data.persist.online`) :
 *   GET  /{slug}/saves/{key}   lire la sauvegarde d'une clé (lien « sur un autre appareil »)
 *   POST /{slug}/saves/{key}   l'écrire : {"data": {...}} (connecté : rattachée au compte si libre)
 *   GET  /{slug}/saves/me      sauvegarde du compte connecté
 *   POST /{slug}/saves/me      l'écrire
 * Écriture en POST : les routes publiques n'acceptent pas PUT en CORS.
 */
#[Route('/api/public/artifacts')]
final class PublicArtifactSaveController extends AbstractController
{
    private const SLUG = '[a-z0-9][a-z0-9-]{2,63}';
    private const KEY = '[A-Za-z0-9_-]{22,64}';

    public function __construct(
        private readonly ArtifactSaveService $saves,
        #[Autowire(service: 'limiter.artifacts_saves')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/{slug}/saves/me', name: 'api_public_artifact_save_me_get', methods: ['GET'], requirements: ['slug' => self::SLUG])]
    public function readMine(string $slug, Request $request): JsonResponse
    {
        $this->consume($request);

        return $this->noStore($this->json($this->saves->readMine($slug, $this->link($request), $this->user())));
    }

    #[Route('/{slug}/saves/me', name: 'api_public_artifact_save_me_post', methods: ['POST'], requirements: ['slug' => self::SLUG])]
    public function writeMine(string $slug, Request $request): JsonResponse
    {
        $this->consume($request);
        [$data, $size] = $this->body($request);

        return $this->noStore($this->json($this->saves->writeMine($slug, $this->link($request), $data, $size, $this->user())));
    }

    #[Route('/{slug}/saves/{key}', name: 'api_public_artifact_save_get', methods: ['GET'], requirements: ['slug' => self::SLUG, 'key' => self::KEY])]
    public function read(string $slug, string $key, Request $request): JsonResponse
    {
        $this->consume($request);

        return $this->noStore($this->json($this->saves->readByKey($slug, $this->link($request), $key, $this->user())));
    }

    #[Route('/{slug}/saves/{key}', name: 'api_public_artifact_save_post', methods: ['POST'], requirements: ['slug' => self::SLUG, 'key' => self::KEY])]
    public function write(string $slug, string $key, Request $request): JsonResponse
    {
        $this->consume($request);
        [$data, $size] = $this->body($request);

        return $this->noStore($this->json($this->saves->writeByKey($slug, $this->link($request), $key, $data, $size, $this->user())));
    }

    #[Route('/{slug}/saves/{key}', name: 'api_public_artifact_save_options', methods: ['OPTIONS'], requirements: ['slug' => self::SLUG, 'key' => '[A-Za-z0-9_-]{2,64}'])]
    public function options(): Response
    {
        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /** @return array{0: mixed, 1: int} */
    private function body(Request $request): array
    {
        $raw = $request->getContent();
        if (\strlen($raw) > ArtifactSaveService::MAX_BYTES + 1024) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Sauvegarde trop volumineuse.');
        }
        /** @var mixed $body */
        $body = json_decode($raw, true);
        if (!\is_array($body) || !\array_key_exists('data', $body)) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Corps attendu : {"data": {...}}.');
        }

        return [$body['data'], \strlen($raw)];
    }

    private function user(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }

    private function link(Request $request): ?string
    {
        return $request->query->getString('k') ?: null;
    }

    private function consume(Request $request): void
    {
        $limit = $this->limiter->create($request->getClientIp() ?: 'anon')->consume(1);
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Trop de sauvegardes, réessayez dans un instant.');
        }
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
