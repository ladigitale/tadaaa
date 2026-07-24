<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\LinkDetectorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/link-detectors')]
#[IsGranted('ROLE_USER')]
final class LinkDetectorController extends AbstractController
{
    public function __construct(
        private readonly LinkDetectorService $linkDetectors,
    ) {
    }

    #[Route('', name: 'api_link_detectors_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(['linkDetectors' => $this->linkDetectors->list($this->user())]);
    }

    #[Route('', name: 'api_link_detectors_replace', methods: ['PUT'])]
    public function replace(Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);
        $raw = $payload['linkDetectors'] ?? null;
        if (!is_array($raw)) {
            return $this->json(['error' => 'linkDetectors array is required.'], Response::HTTP_BAD_REQUEST);
        }

        $detectors = $this->linkDetectors->replaceAll($this->user(), $raw);

        return $this->json(['linkDetectors' => $detectors]);
    }

    #[Route('', name: 'api_link_detectors_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);
        $detector = $this->linkDetectors->create(
            $this->user(),
            is_string($payload['name'] ?? null) ? $payload['name'] : '',
            is_string($payload['pattern'] ?? null) ? $payload['pattern'] : '',
            is_string($payload['urlTemplate'] ?? null) ? $payload['urlTemplate'] : '',
        );

        return $this->json($detector, Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_link_detectors_update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);
        $patch = [];
        if (array_key_exists('name', $payload)) {
            $patch['name'] = is_string($payload['name']) ? $payload['name'] : null;
        }
        if (array_key_exists('pattern', $payload)) {
            $patch['pattern'] = is_string($payload['pattern']) ? $payload['pattern'] : null;
        }
        if (array_key_exists('urlTemplate', $payload)) {
            $patch['urlTemplate'] = is_string($payload['urlTemplate']) ? $payload['urlTemplate'] : null;
        }

        return $this->json($this->linkDetectors->update($this->user(), $id, $patch));
    }

    #[Route('/{id}', name: 'api_link_detectors_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        return $this->json($this->linkDetectors->delete($this->user(), $id));
    }

    private function user(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    /** @return array<string, mixed> */
    private function decodeJson(Request $request): array
    {
        $data = json_decode($request->getContent(), true);

        return is_array($data) ? $data : [];
    }
}
