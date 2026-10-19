<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Artifact;
use App\Entity\User;
use App\Service\ArtifactService;
use App\Service\Room\RoomService;
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
 * Salons d'un artefact : annuaire, entrée (ouverte / code / sur demande), hôte, jetons Mercure.
 *
 * Anonyme (invités sans compte) : l'identité d'un participant est un id + une clé aléatoire
 * renvoyée une seule fois, transmise ensuite dans le corps JSON (jamais en en-tête : le CORS
 * anonyme de l'embed n'autorise pas `Authorization`).
 * L'artefact doit être lisible par le visiteur (public, ou lien `?k=`, ou membre).
 */
#[Route('/api/public/artifacts/{slug}/rooms', requirements: ['slug' => '[a-z0-9][a-z0-9-]{2,63}'])]
final class PublicArtifactRoomController extends AbstractController
{
    private const MAX_BODY_BYTES = 2048;

    public function __construct(
        private readonly ArtifactService $artifacts,
        private readonly RoomService $rooms,
        #[Autowire(service: 'limiter.artifacts_public')]
        private readonly RateLimiterFactoryInterface $publicLimiter,
        #[Autowire(service: 'limiter.artifacts_rooms')]
        private readonly RateLimiterFactoryInterface $roomsLimiter,
    ) {
    }

    #[Route('', name: 'api_public_artifact_rooms_list', methods: ['GET'])]
    public function list(string $slug, Request $request): JsonResponse
    {
        $this->consume($this->publicLimiter, $request);
        $artifact = $this->readable($slug, $request);

        return $this->noStore($this->json(['member' => $this->rooms->listRooms($artifact)]));
    }

    /** Corps : {name, pseudo, access?: "open"|"approval", code?: "xyz"|true, listed?: bool, max?: 2..32} */
    #[Route('', name: 'api_public_artifact_rooms_create', methods: ['POST'])]
    public function create(string $slug, Request $request): JsonResponse
    {
        $this->consume($this->roomsLimiter, $request);
        $artifact = $this->readable($slug, $request);

        return $this->noStore($this->json($this->rooms->createRoom($artifact, $this->body($request)), Response::HTTP_CREATED));
    }

    /** Corps : {pseudo, roomId?, code?} — « pending » (202) si l'hôte doit accepter. */
    #[Route('/join', name: 'api_public_artifact_rooms_join', methods: ['POST'])]
    public function join(string $slug, Request $request): JsonResponse
    {
        $this->consume($this->roomsLimiter, $request);
        $artifact = $this->readable($slug, $request);
        $result = $this->rooms->join($artifact, $this->body($request));

        return $this->noStore($this->json($result, $result['status'] === 'pending' ? Response::HTTP_ACCEPTED : Response::HTTP_OK));
    }

    /** Corps : {memberId, key} — renouvelle le jeton et signale le participant vivant (toutes les ~4 min). */
    #[Route('/{roomId}/token', name: 'api_public_artifact_rooms_token', methods: ['POST'])]
    public function token(string $slug, string $roomId, Request $request): JsonResponse
    {
        $this->consume($this->publicLimiter, $request);
        $artifact = $this->readable($slug, $request);
        $body = $this->body($request);

        return $this->noStore($this->json($this->rooms->refreshToken($artifact, $roomId, $this->str($body, 'memberId'), $this->str($body, 'key'))));
    }

    /** Corps : {memberId, key, targetId, accept: bool} — hôte seulement. */
    #[Route('/{roomId}/requests', name: 'api_public_artifact_rooms_decide', methods: ['POST'])]
    public function decide(string $slug, string $roomId, Request $request): JsonResponse
    {
        $this->consume($this->roomsLimiter, $request);
        $artifact = $this->readable($slug, $request);
        $body = $this->body($request);
        if (!\is_bool($body['accept'] ?? null)) {
            throw new BadRequestHttpException('accept : booléen attendu.');
        }

        return $this->noStore($this->json($this->rooms->decide(
            $artifact,
            $roomId,
            $this->str($body, 'memberId'),
            $this->str($body, 'key'),
            $this->str($body, 'targetId'),
            $body['accept'],
        )));
    }

    /** Corps : {memberId, key, targetId} — hôte seulement. */
    #[Route('/{roomId}/kick', name: 'api_public_artifact_rooms_kick', methods: ['POST'])]
    public function kick(string $slug, string $roomId, Request $request): JsonResponse
    {
        $this->consume($this->roomsLimiter, $request);
        $artifact = $this->readable($slug, $request);
        $body = $this->body($request);

        return $this->noStore($this->json($this->rooms->kick(
            $artifact,
            $roomId,
            $this->str($body, 'memberId'),
            $this->str($body, 'key'),
            $this->str($body, 'targetId'),
        )));
    }

    /** Corps : {memberId, key} — l'hôte qui part ferme le salon. */
    #[Route('/{roomId}/leave', name: 'api_public_artifact_rooms_leave', methods: ['POST'])]
    public function leave(string $slug, string $roomId, Request $request): JsonResponse
    {
        $this->consume($this->roomsLimiter, $request);
        $artifact = $this->readable($slug, $request);
        $body = $this->body($request);

        return $this->noStore($this->json($this->rooms->leave($artifact, $roomId, $this->str($body, 'memberId'), $this->str($body, 'key'))));
    }

    /** Préflight CORS (les en-têtes sont posés par Nelmio / PublicArtifactCorsSubscriber). */
    #[Route('', name: 'api_public_artifact_rooms_options', methods: ['OPTIONS'])]
    #[Route('/{path}', name: 'api_public_artifact_rooms_options_sub', methods: ['OPTIONS'], requirements: ['path' => '.+'])]
    public function options(): Response
    {
        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function readable(string $slug, Request $request): Artifact
    {
        $user = $this->getUser();

        return $this->artifacts->resolveReadableArtifact(
            $slug,
            $request->query->getString('k') ?: null,
            $user instanceof User ? $user : null,
        );
    }

    private function consume(RateLimiterFactoryInterface $factory, Request $request): void
    {
        $limit = $factory->create($request->getClientIp() ?: 'anon')->consume(1);
        if (!$limit->isAccepted()) {
            $retry = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException($retry, 'Trop de requêtes, réessayez dans quelques secondes.');
        }
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $raw = $request->getContent();
        if (\strlen($raw) > self::MAX_BODY_BYTES) {
            throw new BadRequestHttpException('Corps trop volumineux.');
        }
        if ($raw === '') {
            return [];
        }
        /** @var mixed $body */
        $body = json_decode($raw, true);
        if (!\is_array($body) || array_is_list($body) && $body !== []) {
            throw new BadRequestHttpException('Objet JSON attendu.');
        }

        return $body;
    }

    /** @param array<string, mixed> $body */
    private function str(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        if (!\is_string($value) || $value === '') {
            throw new BadRequestHttpException(sprintf('%s requis.', $key));
        }

        return $value;
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
