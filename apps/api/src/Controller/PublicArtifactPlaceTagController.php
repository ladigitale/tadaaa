<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\ArtifactPlaceTagService;
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
 * « Passés par là » des lieux partagés (en-tête X-Place-Device : clé aléatoire gardée par l'appareil) :
 *   POST /{slug}/places/{name}/visitor                 {"pseudo"?, "avatar"?: data:image/svg+xml} → {id, pseudo, avatar}
 *   GET  /{slug}/places/{name}/{id}/tags               → {count, tags: [{id, pseudo, avatar, at, mine}], mine}
 *   POST /{slug}/places/{name}/{id}/tags               {"at": {lat, lon, accuracy, seen: true}} → {count, at, first}
 *   GET  /{slug}/places/{name}/avatars/{visitorId}     SVG brut (CSP stricte)
 *   POST /{slug}/places/{name}/avatars/{visitorId}/report  signalement (masqué au 3e)
 */
#[Route('/api/public/artifacts')]
final class PublicArtifactPlaceTagController extends AbstractController
{
    private const SLUG = '[a-z0-9][a-z0-9-]{2,63}';
    private const NAME = '[a-zA-Z][a-zA-Z0-9_-]{0,63}';
    private const ID = '[0-9a-fA-F-]{36}';

    public function __construct(
        private readonly ArtifactPlaceTagService $tags,
        #[Autowire(service: 'limiter.artifacts_places')]
        private readonly RateLimiterFactoryInterface $limiter,
        #[Autowire(service: 'limiter.artifacts_public')]
        private readonly RateLimiterFactoryInterface $readLimiter,
    ) {
    }

    #[Route('/{slug}/places/{name}/visitor', name: 'api_public_artifact_places_visitor', methods: ['POST'], requirements: ['slug' => self::SLUG, 'name' => self::NAME])]
    public function visitor(string $slug, string $name, Request $request): JsonResponse
    {
        $this->consume($this->limiter, $request);
        $raw = $request->getContent();
        if (\strlen($raw) > \App\Entity\ArtifactPlaceVisitor::MAX_AVATAR + 4096) {
            throw new BadRequestHttpException('Avatar trop volumineux.');
        }
        /** @var mixed $in */
        $in = json_decode($raw, true);
        if (!\is_array($in)) {
            throw new BadRequestHttpException('Corps attendu : {"pseudo"?, "avatar"?: "data:image/svg+xml;base64,…"}.');
        }

        return $this->noStore($this->json($this->tags->saveVisitor($slug, $this->link($request), $name, $request->headers->get('X-Place-Device'), $in['pseudo'] ?? null, $in['avatar'] ?? null, $this->user())));
    }

    #[Route('/{slug}/places/{name}/{id}/tags', name: 'api_public_artifact_places_tags', methods: ['GET'], requirements: ['slug' => self::SLUG, 'name' => self::NAME, 'id' => self::ID])]
    public function list(string $slug, string $name, string $id, Request $request): JsonResponse
    {
        $this->consume($this->readLimiter, $request);

        return $this->noStore($this->json($this->tags->list($slug, $this->link($request), $name, $id, $request->headers->get('X-Place-Device'), $this->user())));
    }

    #[Route('/{slug}/places/{name}/{id}/tags', name: 'api_public_artifact_places_tag', methods: ['POST'], requirements: ['slug' => self::SLUG, 'name' => self::NAME, 'id' => self::ID])]
    public function tag(string $slug, string $name, string $id, Request $request): JsonResponse
    {
        $this->consume($this->limiter, $request);
        /** @var mixed $in */
        $in = json_decode($request->getContent() ?: '{}', true);
        $at = \is_array($in) ? ($in['at'] ?? null) : null;

        return $this->noStore($this->json($this->tags->tag($slug, $this->link($request), $name, $id, $request->headers->get('X-Place-Device'), $at, $this->user()), 201));
    }

    #[Route('/{slug}/places/{name}/avatars/{visitorId}', name: 'api_public_artifact_places_avatar', methods: ['GET'], requirements: ['slug' => self::SLUG, 'name' => self::NAME, 'visitorId' => self::ID])]
    public function avatar(string $slug, string $name, string $visitorId, Request $request): Response
    {
        $this->consume($this->readLimiter, $request);
        $svg = $this->tags->avatarSvg($slug, $this->link($request), $name, $visitorId, $this->user());
        $r = new Response($svg, 200, ['Content-Type' => 'image/svg+xml; charset=utf-8']);
        // Une image, jamais un document actif : rien ne s'exécute ni ne se charge, même ouverte seule.
        $r->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
        $r->headers->set('X-Content-Type-Options', 'nosniff');
        $r->headers->set('Cross-Origin-Resource-Policy', 'cross-origin');
        // L'URL porte ?v=<date de mise à jour> : un avatar redessiné change d'URL.
        $r->headers->set('Cache-Control', 'public, max-age=86400');

        return $r;
    }

    #[Route('/{slug}/places/{name}/avatars/{visitorId}/report', name: 'api_public_artifact_places_avatar_report', methods: ['POST'], requirements: ['slug' => self::SLUG, 'name' => self::NAME, 'visitorId' => self::ID])]
    public function report(string $slug, string $name, string $visitorId, Request $request): Response
    {
        $this->consume($this->limiter, $request);
        $this->tags->reportVisitor($slug, $this->link($request), $name, $visitorId, $this->user());

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route('/{slug}/places/{name}/visitor', name: 'api_public_artifact_places_visitor_options', methods: ['OPTIONS'], requirements: ['slug' => self::SLUG, 'name' => self::NAME])]
    #[Route('/{slug}/places/{name}/avatars/{visitorId}/report', name: 'api_public_artifact_places_avatar_report_options', methods: ['OPTIONS'], requirements: ['slug' => self::SLUG, 'name' => self::NAME, 'visitorId' => self::ID])]
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
