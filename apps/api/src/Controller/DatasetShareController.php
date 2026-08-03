<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DatasetMemberRole;
use App\Entity\User;
use App\Service\DatasetAccessService;
use App\Service\DatasetShareService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[IsGranted('ROLE_USER')]
final class DatasetShareController extends AbstractController
{
    public function __construct(
        private readonly DatasetShareService $share,
        private readonly DatasetAccessService $access,
    ) {
    }

    #[Route('/api/datasets/{id}/invites', name: 'api_dataset_invites_create', methods: ['POST'])]
    public function createInvite(string $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $dataset = $this->access->requireOwnedById($user, $this->parseUuid($id));
        /** @var array{role?: string} $payload */
        $payload = json_decode($request->getContent(), true) ?? [];
        $role = $this->parseRole($payload['role'] ?? 'reader');

        return $this->json($this->share->createInvite($user, $dataset, $role), Response::HTTP_CREATED);
    }

    /**
     * Invite by email: creates a link invite and pushes a Mercure event on the invitee's user topic
     * when an active account exists for that address.
     */
    #[Route('/api/datasets/{id}/invites/email', name: 'api_dataset_invites_email', methods: ['POST'])]
    public function inviteByEmail(string $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $dataset = $this->access->requireOwnedById($user, $this->parseUuid($id));
        /** @var array{email?: string, role?: string} $payload */
        $payload = json_decode($request->getContent(), true) ?? [];
        $email = is_string($payload['email'] ?? null) ? $payload['email'] : '';
        $role = $this->parseRole($payload['role'] ?? 'reader');

        return $this->json(
            $this->share->inviteByEmail($user, $dataset, $email, $role),
            Response::HTTP_CREATED,
        );
    }

    #[Route('/api/datasets/{id}/members', name: 'api_dataset_members_list', methods: ['GET'])]
    public function listMembers(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $dataset = $this->access->requireOwnedById($user, $this->parseUuid($id));

        return $this->json(['member' => $this->share->listMembers($user, $dataset)]);
    }

    #[Route('/api/datasets/{id}/members/{userId}', name: 'api_dataset_members_remove', methods: ['DELETE'])]
    public function removeMember(string $id, string $userId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $dataset = $this->access->requireOwnedById($user, $this->parseUuid($id));
        $this->share->removeMember($user, $dataset, $userId);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/invites/{token}', name: 'api_invites_preview', methods: ['GET'])]
    public function previewInvite(string $token): JsonResponse
    {
        return $this->json($this->share->previewInvite($token));
    }

    #[Route('/api/invites/{token}/accept', name: 'api_invites_accept', methods: ['POST'])]
    public function acceptInvite(string $token): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json($this->share->acceptInvite($user, $token));
    }

    private function parseUuid(string $id): Uuid
    {
        try {
            return Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException('Jeu introuvable.');
        }
    }

    private function parseRole(mixed $raw): DatasetMemberRole
    {
        if (!is_string($raw)) {
            throw $this->createBadRequest('Rôle invalide (writer ou reader).');
        }

        return match (strtolower(trim($raw))) {
            'writer' => DatasetMemberRole::Writer,
            'reader' => DatasetMemberRole::Reader,
            default => throw $this->createBadRequest('Rôle invalide (writer ou reader).'),
        };
    }

    private function createBadRequest(string $message): \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
    {
        return new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException($message);
    }
}
