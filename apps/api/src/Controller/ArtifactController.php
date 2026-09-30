<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\ArtifactDataService;
use App\Service\ArtifactDocumentValidator;
use App\Service\ArtifactService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/artifacts')]
#[IsGranted('ROLE_USER')]
final class ArtifactController extends AbstractController
{
    public function __construct(
        private readonly ArtifactService $artifacts,
        private readonly ArtifactDataService $artifactData,
        private readonly ArtifactDocumentValidator $validator,
    ) {
    }

    #[Route('', name: 'api_artifacts_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $datasetId = $request->query->getString('dataset') ?: null;

        return $this->json(['member' => $this->artifacts->listForUser($user, $datasetId)]);
    }

    #[Route('', name: 'api_artifacts_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $body = $this->jsonBody($request);
        $datasetId = $body['datasetId'] ?? null;
        $title = $body['title'] ?? null;
        $document = $body['document'] ?? null;
        if (!\is_string($datasetId) || !\is_string($title) || !\is_array($document)) {
            throw new BadRequestHttpException('datasetId, title et document sont requis.');
        }

        $created = $this->artifacts->create(
            $user,
            $datasetId,
            $title,
            $document,
            \is_string($body['slug'] ?? null) ? $body['slug'] : null,
            \is_string($body['visibility'] ?? null) ? $body['visibility'] : 'private',
            \is_string($body['description'] ?? null) ? $body['description'] : null,
            $request->getClientIp(),
        );

        return $this->json($created, 201);
    }

    #[Route('/validate', name: 'api_artifacts_validate', methods: ['POST'])]
    public function validate(Request $request): JsonResponse
    {
        $body = $this->jsonBody($request);
        $document = $body['document'] ?? $body;

        return $this->json($this->validator->validate($document));
    }

    #[Route('/{id}', name: 'api_artifacts_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json($this->artifacts->getForUser($user, $id));
    }

    #[Route('/{id}', name: 'api_artifacts_patch', methods: ['PATCH'])]
    public function patch(string $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json($this->artifacts->patch($user, $id, $this->jsonBody($request), $request->getClientIp()));
    }

    #[Route('/{id}', name: 'api_artifacts_delete', methods: ['DELETE'])]
    public function delete(string $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->artifacts->delete($user, $id, $request->getClientIp());

        return $this->json(null, 204);
    }

    #[Route('/{id}/document', name: 'api_artifacts_put_document', methods: ['PUT'])]
    public function putDocument(string $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $body = $this->jsonBody($request);
        $document = $body['document'] ?? null;
        if (!\is_array($document)) {
            throw new BadRequestHttpException('document requis.');
        }
        $note = \is_string($body['note'] ?? null) ? $body['note'] : null;

        return $this->json($this->artifacts->putDocument($user, $id, $document, $note, $request->getClientIp()));
    }

    #[Route('/{id}/versions', name: 'api_artifacts_versions', methods: ['GET'])]
    public function versions(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json(['member' => $this->artifacts->listVersions($user, $id)]);
    }

    #[Route('/{id}/versions/{n}/restore', name: 'api_artifacts_restore', methods: ['POST'], requirements: ['n' => '\\d+'])]
    public function restore(string $id, int $n, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json($this->artifacts->restoreVersion($user, $id, $n, $request->getClientIp()));
    }

    #[Route('/{id}/rotate-link', name: 'api_artifacts_rotate_link', methods: ['POST'])]
    public function rotateLink(string $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json($this->artifacts->rotateLink($user, $id, $request->getClientIp()));
    }

    #[Route('/{id}/collections/{name}/records', name: 'api_artifacts_records_list', methods: ['GET'], requirements: ['name' => '[a-z][a-z0-9_]{0,63}'])]
    public function listRecords(string $id, string $name): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json(['member' => $this->artifactData->readForUser($user, $id, $name)]);
    }

    #[Route('/{id}/collections/{name}/records', name: 'api_artifacts_records_create', methods: ['POST'], requirements: ['name' => '[a-z][a-z0-9_]{0,63}'])]
    public function createRecord(string $id, string $name, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $body = $this->jsonBody($request);
        $data = $body['data'] ?? $body;
        if (!\is_array($data)) {
            throw new BadRequestHttpException('data requis.');
        }

        return $this->json($this->artifactData->createRecord($user, $id, $name, $data), 201);
    }

    #[Route('/{id}/collections/{name}/records/{recordId}', name: 'api_artifacts_records_patch', methods: ['PATCH'], requirements: ['name' => '[a-z][a-z0-9_]{0,63}'])]
    public function patchRecord(string $id, string $name, string $recordId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $body = $this->jsonBody($request);
        $data = $body['data'] ?? $body;
        if (!\is_array($data)) {
            throw new BadRequestHttpException('data requis.');
        }

        return $this->json($this->artifactData->updateRecord($user, $id, $name, $recordId, $data));
    }

    #[Route('/{id}/collections/{name}/records/{recordId}', name: 'api_artifacts_records_delete', methods: ['DELETE'], requirements: ['name' => '[a-z][a-z0-9_]{0,63}'])]
    public function deleteRecord(string $id, string $name, string $recordId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->artifactData->deleteRecord($user, $id, $name, $recordId);

        return $this->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function jsonBody(Request $request): array
    {
        /** @var mixed $body */
        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            throw new BadRequestHttpException('JSON object expected.');
        }

        return $body;
    }
}
