<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DatasetAppDocument;
use App\Entity\User;
use App\Repository\DatasetAppDocumentRepository;
use App\Repository\DatasetRepository;
use App\Service\DatasetAccessService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * GET/PUT JSON app documents attached to a dataset (Belts, future apps).
 */
#[Route('/api')]
#[IsGranted('ROLE_USER')]
final class DatasetAppDocumentController extends AbstractController
{
    public function __construct(
        private readonly DatasetRepository $datasets,
        private readonly DatasetAppDocumentRepository $documents,
        private readonly DatasetAccessService $access,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** List datasets that already have a document for this app. */
    #[Route('/apps/{appId}/datasets', name: 'api_app_datasets_list', methods: ['GET'])]
    public function listByApp(string $appId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $appId)) {
            throw new BadRequestHttpException('Invalid appId.');
        }

        $rows = $this->documents->findSummariesForUserApp($user, $appId);

        return $this->json(['member' => $rows]);
    }

    #[Route('/datasets/{id}/apps', name: 'api_dataset_apps_list', methods: ['GET'])]
    public function listApps(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $dataset = $this->requireDataset($id);
        $this->access->assertCanRead($user, $dataset);

        $docs = $this->documents->findBy(['dataset' => $dataset]);
        $member = array_map(
            static fn (DatasetAppDocument $doc): array => [
                'appId' => $doc->getAppId(),
                'updatedAt' => $doc->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            ],
            $docs,
        );

        return $this->json(['member' => $member]);
    }

    #[Route('/datasets/{id}/apps/{appId}', name: 'api_dataset_app_document_get', methods: ['GET'])]
    public function get(string $id, string $appId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $dataset = $this->requireDataset($id);
        $this->access->assertCanRead($user, $dataset);

        $doc = $this->documents->findOneForDatasetApp($dataset, $appId);
        if ($doc === null) {
            return $this->json([
                'appId' => $appId,
                'datasetId' => $dataset->getId()->toRfc4122(),
                'payload' => null,
                'updatedAt' => null,
            ]);
        }

        return $this->json($this->serialize($doc));
    }

    #[Route('/datasets/{id}/apps/{appId}', name: 'api_dataset_app_document_put', methods: ['PUT'])]
    public function put(string $id, string $appId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $dataset = $this->requireDataset($id);
        $this->access->assertCanWrite($user, $dataset);

        if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $appId)) {
            throw new BadRequestHttpException('Invalid appId.');
        }

        /** @var mixed $body */
        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            throw new BadRequestHttpException('JSON object expected.');
        }

        /** @var array<string, mixed> $payload */
        $payload = \array_key_exists('payload', $body) && \is_array($body['payload'])
            ? $body['payload']
            : $body;

        $doc = $this->documents->findOneForDatasetApp($dataset, $appId);
        if ($doc === null) {
            $doc = new DatasetAppDocument($dataset, $appId);
            $this->em->persist($doc);
        }
        $doc->setPayload($payload);
        $dataset->touch();
        $this->em->flush();

        return $this->json($this->serialize($doc));
    }

    private function requireDataset(string $id): \App\Entity\Dataset
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Jeu de données introuvable.');
        }
        $dataset = $this->datasets->find($uuid);
        if ($dataset === null) {
            throw new NotFoundHttpException('Jeu de données introuvable.');
        }

        return $dataset;
    }

    /** @return array<string, mixed> */
    private function serialize(DatasetAppDocument $doc): array
    {
        return [
            'appId' => $doc->getAppId(),
            'datasetId' => $doc->getDataset()->getId()->toRfc4122(),
            'payload' => $doc->getPayload(),
            'updatedAt' => $doc->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
