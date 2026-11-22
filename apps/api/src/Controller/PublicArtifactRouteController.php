<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ArtifactRoute;
use App\Entity\User;
use App\Service\ArtifactRouteService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Parcours entre les lieux d'un artefact (`data.places.<nom>`), mêmes droits de lecture que l'artefact :
 *
 *   GET    /{slug}/places/{name}/routes?bbox=o,s,e,n     parcours qui touchent la zone (étapes avec lat/lon)
 *   GET    /{slug}/places/{name}/routes/{id}             un parcours et les champs courts de ses lieux
 *   GET    /{slug}/places/{name}/routes/{id}/sounds/{i}  son propre d'une étape
 *   POST   /{slug}/places/{name}/routes                  {title, description?, ordered, mode, pseudo?, steps} → {id, deleteToken}
 *   DELETE /{slug}/places/{name}/routes/{id}             en-tête X-Place-Token (créateur) ou membre
 *   POST   /{slug}/places/{name}/routes/{id}/report      signalement (masqué au 3e)
 */
#[Route('/api/public/artifacts')]
final class PublicArtifactRouteController extends AbstractController
{
    private const SLUG = '[a-z0-9][a-z0-9-]{2,63}';
    private const NAME = '[a-zA-Z][a-zA-Z0-9_-]{0,63}';
    private const ID = '[0-9a-fA-F-]{36}';

    public function __construct(
        private readonly ArtifactRouteService $routes,
        #[Autowire(service: 'limiter.artifacts_places')]
        private readonly RateLimiterFactoryInterface $limiter,
        #[Autowire(service: 'limiter.artifacts_public')]
        private readonly RateLimiterFactoryInterface $readLimiter,
    ) {
    }

    #[Route('/{slug}/places/{name}/routes', name: 'api_public_artifact_routes_list', methods: ['GET'], requirements: ['slug' => self::SLUG, 'name' => self::NAME])]
    public function list(string $slug, string $name, Request $request): JsonResponse
    {
        $this->consume($this->readLimiter, $request);
        $parts = array_map('trim', explode(',', $request->query->getString('bbox')));
        if (\count($parts) !== 4 || \count(array_filter($parts, 'is_numeric')) !== 4) {
            throw new BadRequestHttpException('bbox : ouest,sud,est,nord en degrés.');
        }
        $b = array_map('floatval', $parts);

        return $this->noStore($this->json($this->routes->listBox($slug, $this->link($request), $name, [$b[0], $b[1], $b[2], $b[3]], $this->user())));
    }

    #[Route('/{slug}/places/{name}/routes/{id}', name: 'api_public_artifact_routes_get', methods: ['GET'], requirements: ['slug' => self::SLUG, 'name' => self::NAME, 'id' => self::ID])]
    public function get(string $slug, string $name, string $id, Request $request): JsonResponse
    {
        $this->consume($this->readLimiter, $request);

        return $this->noStore($this->json($this->routes->get($slug, $this->link($request), $name, $id, $this->user())));
    }

    #[Route('/{slug}/places/{name}/routes/{id}/sounds/{index}', name: 'api_public_artifact_routes_sound', methods: ['GET'], requirements: ['slug' => self::SLUG, 'name' => self::NAME, 'id' => self::ID, 'index' => '\d{1,2}'])]
    public function sound(string $slug, string $name, string $id, int $index, Request $request): JsonResponse
    {
        $this->consume($this->readLimiter, $request);

        return $this->noStore($this->json($this->routes->sound($slug, $this->link($request), $name, $id, $index, $this->user())));
    }

    #[Route('/{slug}/places/{name}/routes', name: 'api_public_artifact_routes_create', methods: ['POST'], requirements: ['slug' => self::SLUG, 'name' => self::NAME])]
    public function create(string $slug, string $name, Request $request): JsonResponse
    {
        $this->consume($this->limiter, $request);
        $raw = $request->getContent();
        if (\strlen($raw) > ArtifactRoute::MAX_SOUNDS + 65536) {
            throw new BadRequestHttpException('Parcours trop volumineux.');
        }

        return $this->noStore($this->json($this->routes->create($slug, $this->link($request), $name, json_decode($raw, true), $this->user(), $request->getClientIp() ?: 'anon'), 201));
    }

    #[Route('/{slug}/places/{name}/routes/{id}', name: 'api_public_artifact_routes_delete', methods: ['DELETE'], requirements: ['slug' => self::SLUG, 'name' => self::NAME, 'id' => self::ID])]
    public function delete(string $slug, string $name, string $id, Request $request): Response
    {
        $this->consume($this->limiter, $request);
        $this->routes->delete($slug, $this->link($request), $name, $id, $request->headers->get('X-Place-Token'), $this->user());

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route('/{slug}/places/{name}/routes/{id}/report', name: 'api_public_artifact_routes_report', methods: ['POST'], requirements: ['slug' => self::SLUG, 'name' => self::NAME, 'id' => self::ID])]
    public function report(string $slug, string $name, string $id, Request $request): JsonResponse
    {
        $this->consume($this->limiter, $request);

        return $this->noStore($this->json($this->routes->report($slug, $this->link($request), $name, $id, $this->user())));
    }

    #[Route('/{slug}/places/{name}/routes', name: 'api_public_artifact_routes_options', methods: ['OPTIONS'], requirements: ['slug' => self::SLUG, 'name' => self::NAME])]
    #[Route('/{slug}/places/{name}/routes/{id}', name: 'api_public_artifact_routes_item_options', methods: ['OPTIONS'], requirements: ['slug' => self::SLUG, 'name' => self::NAME, 'id' => self::ID])]
    #[Route('/{slug}/places/{name}/routes/{id}/report', name: 'api_public_artifact_routes_report_options', methods: ['OPTIONS'], requirements: ['slug' => self::SLUG, 'name' => self::NAME, 'id' => self::ID])]
    #[Route('/{slug}/places/{name}/routes/{id}/sounds/{index}', name: 'api_public_artifact_routes_sound_options', methods: ['OPTIONS'], requirements: ['slug' => self::SLUG, 'name' => self::NAME, 'id' => self::ID, 'index' => '\d{1,2}'])]
    public function options(): Response
    {
        return new Response('', Response::HTTP_NO_CONTENT);
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

    private function consume(RateLimiterFactoryInterface $limiter, Request $request): void
    {
        $limit = $limiter->create($request->getClientIp() ?: 'anon')->consume(1);
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Trop de requêtes, réessayez dans un instant.');
        }
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
