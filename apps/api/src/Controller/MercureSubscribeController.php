<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\DatasetRepository;
use App\Service\DatasetAccessService;
use App\Service\DatasetRealtimePublisher;
use App\Service\MercureFeature;
use App\Util\BaseIdParser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class MercureSubscribeController extends AbstractController
{
    private const TOKEN_TTL_SECONDS = 3600;

    public function __construct(
        private readonly HubInterface $hub,
        private readonly DatasetRepository $datasets,
        private readonly DatasetAccessService $access,
        private readonly MercureFeature $mercure,
    ) {
    }

    /**
     * Credentials for EventSource: always includes the user topic (share events),
     * and optionally the active dataset topic for sync pings.
     */
    #[Route('/api/mercure', name: 'api_mercure_session', methods: ['GET'])]
    public function session(Request $request): JsonResponse
    {
        $this->assertMercureReady();

        /** @var User $user */
        $user = $this->getUser();
        $topics = [DatasetRealtimePublisher::topicForUser($user)];

        $baseIdRaw = $request->query->get('baseId');
        if (is_string($baseIdRaw) && trim($baseIdRaw) !== '') {
            $topics[] = $this->topicForReadableDataset($user, trim($baseIdRaw));
        }

        return $this->credentialsResponse($topics);
    }

    /**
     * Credentials for EventSource subscription (hub URL + subscriber JWT + topic).
     *
     * @deprecated Prefer GET /api/mercure?baseId=… (also covers user notifications).
     */
    #[Route('/api/datasets/{baseId}/mercure', name: 'api_dataset_mercure_subscribe', methods: ['GET'])]
    public function subscribe(string $baseId): JsonResponse
    {
        $this->assertMercureReady();

        /** @var User $user */
        $user = $this->getUser();
        $datasetTopic = $this->topicForReadableDataset($user, $baseId);
        $topics = [
            DatasetRealtimePublisher::topicForUser($user),
            $datasetTopic,
        ];

        $response = $this->credentialsResponse($topics);
        // Keep legacy shape: single `topic` = dataset (clients that only read one topic).
        $data = json_decode($response->getContent() ?: '{}', true, 512, \JSON_THROW_ON_ERROR);
        $data['topic'] = $datasetTopic;

        return $this->json($data);
    }

    private function assertMercureReady(): void
    {
        if (!$this->mercure->isEnabled()) {
            throw new ServiceUnavailableHttpException(null, 'Mercure désactivé sur cet environnement.');
        }

        if ($this->hub->getFactory() === null) {
            throw new ServiceUnavailableHttpException(null, 'Mercure JWT factory indisponible.');
        }
    }

    private function topicForReadableDataset(User $user, string $baseId): string
    {
        try {
            $uuid = BaseIdParser::parse($baseId);
        } catch (\Throwable) {
            throw new BadRequestHttpException('baseId invalide.');
        }

        $dataset = $this->datasets->findOneByBaseId($uuid);
        if ($dataset === null) {
            throw new NotFoundHttpException('Jeu de données introuvable.');
        }

        $this->access->assertCanRead($user, $dataset);

        return DatasetRealtimePublisher::topicForDataset($dataset);
    }

    /**
     * @param list<string> $topics
     */
    private function credentialsResponse(array $topics): JsonResponse
    {
        $factory = $this->hub->getFactory();
        if ($factory === null) {
            throw new ServiceUnavailableHttpException(null, 'Mercure JWT factory indisponible.');
        }

        $token = $factory->create(
            $topics,
            [],
            ['exp' => new \DateTimeImmutable('+'.self::TOKEN_TTL_SECONDS.' seconds')],
        );

        return $this->json([
            'hubUrl' => $this->hub->getPublicUrl(),
            'topic' => $topics[0],
            'topics' => $topics,
            'token' => $token,
            'expiresIn' => self::TOKEN_TTL_SECONDS,
        ]);
    }
}
