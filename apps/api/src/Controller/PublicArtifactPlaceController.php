<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\ArtifactPlaceService;
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
 * Lieux partagés d'un artefact (`data.places.<nom>`), mêmes droits de lecture que l'artefact (`?k=` pour un lien) :
 *
 *   GET    /{slug}/places/{name}?lat=&lon=&radius=   lieux proches (champs courts), du plus proche au plus lointain
 *   GET    /{slug}/places/{name}?bbox=o,s,e,n         lieux d'une zone de carte, ou groupes {lat, lon, count} si trop nombreux
 *   GET    /{slug}/places/{name}/{id}                 un lieu complet (body)
 *   POST   /{slug}/places/{name}                      {"lat", "lon", "body": {...}} → {id, deleteToken}
 *   DELETE /{slug}/places/{name}/{id}                 en-tête X-Place-Token (poseur), auteur connecté ou membre
 *   POST   /{slug}/places/{name}/{id}/report          signalement (masqué au 3e)
 *   POST   /{slug}/places/{name}/{id}/hidden          membre : {"hidden": bool}
 */
#[Route('/api/public/artifacts')]
final class PublicArtifactPlaceController extends AbstractController
{
    private const SLUG = '[a-z0-9][a-z0-9-]{2,63}';
    private const NAME = '[a-zA-Z][a-zA-Z0-9_-]{0,63}';
    private const ID = '[0-9a-fA-F-]{36}';

    public function __construct(
        private readonly ArtifactPlaceService $places,
        #[Autowire(service: 'limiter.artifacts_places')]
        private readonly RateLimiterFactoryInterface $limiter,
        #[Autowire(service: 'limiter.artifacts_public')]
        private readonly RateLimiterFactoryInterface $readLimiter,
    ) {
    }

    #[Route('/{slug}/places/{name}', name: 'api_public_artifact_places_list', methods: ['GET'], requirements: ['slug' => self::SLUG, 'name' => self::NAME])]
    public function list(string $slug, string $name, Request $request): JsonResponse
    {
        $this->consume($this->readLimiter, $request);
        $q = $request->query;
        if ($q->has('bbox')) {
            $parts = array_map('trim', explode(',', $q->getString('bbox')));
            if (\count($parts) !== 4 || \count(array_filter($parts, 'is_numeric')) !== 4) {
                throw new BadRequestHttpException('bbox : ouest,sud,est,nord en degrés.');
            }
            $bbox = array_map('floatval', $parts);

            return $this->noStore($this->json($this->places->listBox($slug, $this->link($request), $name, [$bbox[0], $bbox[1], $bbox[2], $bbox[3]], $this->user())));
        }
        $num = static fn (string $k): ?float => is_numeric($q->get($k)) ? (float) $q->get($k) : null;
        $radius = is_numeric($q->get('radius')) ? (int) $q->get('radius') : null;

        return $this->noStore($this->json($this->places->list($slug, $this->link($request), $name, $num('lat'), $num('lon'), $radius, $this->user(), $q->getBoolean('hidden'))));
    }

    #[Route('/{slug}/places/{name}/{id}', name: 'api_public_artifact_places_get', methods: ['GET'], requirements: ['slug' => self::SLUG, 'name' => self::NAME, 'id' => self::ID])]
    public function get(string $slug, string $name, string $id, Request $request): JsonResponse
    {
        $this->consume($this->readLimiter, $request);
        $response = $this->json($this->places->get($slug, $this->link($request), $name, $id, $this->user()), 200, [], ['preserve_empty_objects' => true]);
        $response->headers->set('Cache-Control', 'private, max-age=60');

        return $response;
    }

    #[Route('/{slug}/places/{name}', name: 'api_public_artifact_places_create', methods: ['POST'], requirements: ['slug' => self::SLUG, 'name' => self::NAME])]
    public function create(string $slug, string $name, Request $request): JsonResponse
    {
        $this->consume($this->limiter, $request);
        $raw = $request->getContent();
        if (\strlen($raw) > ArtifactPlaceService::HARD_MAX_BYTES + 2048) {
            throw new BadRequestHttpException('Lieu trop volumineux.');
        }
        /** @var mixed $in */
        $in = json_decode($raw, true);
        if (!\is_array($in) || !\array_key_exists('body', $in)) {
            throw new BadRequestHttpException('Corps attendu : {"lat", "lon", "body": {...}}.');
        }
        $size = \strlen((string) json_encode($in['body'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
        $out = $this->places->create($slug, $this->link($request), $name, $in['lat'] ?? null, $in['lon'] ?? null, $in['body'], $size, $this->user(), $request->getClientIp() ?: 'anon');

        return $this->noStore($this->json($out, 201));
    }

    #[Route('/{slug}/places/{name}/{id}', name: 'api_public_artifact_places_delete', methods: ['DELETE'], requirements: ['slug' => self::SLUG, 'name' => self::NAME, 'id' => self::ID])]
    public function delete(string $slug, string $name, string $id, Request $request): Response
    {
        $this->consume($this->limiter, $request);
        $this->places->delete($slug, $this->link($request), $name, $id, $request->headers->get('X-Place-Token'), $this->user());

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route('/{slug}/places/{name}/{id}/report', name: 'api_public_artifact_places_report', methods: ['POST'], requirements: ['slug' => self::SLUG, 'name' => self::NAME, 'id' => self::ID])]
    public function report(string $slug, string $name, string $id, Request $request): JsonResponse
    {
        $this->consume($this->limiter, $request);

        return $this->noStore($this->json($this->places->report($slug, $this->link($request), $name, $id, $this->user())));
    }

    #[Route('/{slug}/places/{name}/{id}/hidden', name: 'api_public_artifact_places_hidden', methods: ['POST'], requirements: ['slug' => self::SLUG, 'name' => self::NAME, 'id' => self::ID])]
    public function hidden(string $slug, string $name, string $id, Request $request): Response
    {
        $this->consume($this->limiter, $request);
        /** @var mixed $in */
        $in = json_decode($request->getContent(), true);
        $this->places->setHidden($slug, $this->link($request), $name, $id, \is_array($in) && ($in['hidden'] ?? false) === true, $this->user());

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route('/{slug}/places/{name}', name: 'api_public_artifact_places_options', methods: ['OPTIONS'], requirements: ['slug' => self::SLUG, 'name' => self::NAME])]
    #[Route('/{slug}/places/{name}/{id}', name: 'api_public_artifact_places_item_options', methods: ['OPTIONS'], requirements: ['slug' => self::SLUG, 'name' => self::NAME, 'id' => self::ID])]
    #[Route('/{slug}/places/{name}/{id}/{action}', name: 'api_public_artifact_places_action_options', methods: ['OPTIONS'], requirements: ['slug' => self::SLUG, 'name' => self::NAME, 'id' => self::ID, 'action' => 'report|hidden'])]
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
