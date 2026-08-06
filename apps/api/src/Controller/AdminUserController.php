<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\AdminQuotaInput;
use App\Entity\User;
use App\Entity\UserStatus;
use App\Repository\UserRepository;
use App\Service\AccountMailer;
use App\Service\UserAccountDeletion;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/admin/users')]
#[IsGranted('ROLE_ADMIN')]
final class AdminUserController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountMailer $accountMailer,
        private readonly UserAccountDeletion $userAccountDeletion,
    ) {
    }

    #[Route('', name: 'api_admin_users_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $statusParam = $request->query->getString('status');
        $qb = $this->users->createQueryBuilder('u')->orderBy('u.createdAt', 'DESC');

        if ($statusParam !== '') {
            $status = UserStatus::tryFrom($statusParam);
            if ($status === null) {
                return $this->json(['error' => 'Statut invalide.'], Response::HTTP_BAD_REQUEST);
            }
            $qb->andWhere('u.status = :status')->setParameter('status', $status);
        }

        /** @var list<User> $members */
        $members = $qb->getQuery()->getResult();

        return $this->json([
            'member' => array_map($this->serialize(...), $members),
        ]);
    }

    #[Route('/{id}/approve', name: 'api_admin_users_approve', methods: ['POST'])]
    public function approve(string $id, Request $request): JsonResponse
    {
        return $this->moderate($id, UserStatus::Active, 'reactivated', $this->readMessage($request));
    }

    #[Route('/{id}/reject', name: 'api_admin_users_reject', methods: ['POST'])]
    public function reject(string $id, Request $request): JsonResponse
    {
        return $this->moderate($id, UserStatus::Rejected, 'rejected', $this->readMessage($request));
    }

    #[Route('/{id}/disable', name: 'api_admin_users_disable', methods: ['POST'])]
    public function disable(string $id, Request $request): JsonResponse
    {
        return $this->moderate($id, UserStatus::Disabled, 'disabled', $this->readMessage($request));
    }

    #[Route('/{id}', name: 'api_admin_users_delete', methods: ['DELETE'])]
    public function delete(string $id, Request $request): JsonResponse
    {
        $user = $this->findUser($id);
        if ($user === null) {
            return $this->json(['error' => 'Utilisateur introuvable.'], Response::HTTP_NOT_FOUND);
        }

        /** @var User $current */
        $current = $this->getUser();
        if ($user->getId()->equals($current->getId())) {
            return $this->json(
                ['error' => 'Vous ne pouvez pas supprimer votre propre compte.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $email = $user->getEmail();
        $this->userAccountDeletion->delete($user, 'deleted', $this->readMessage($request));

        return $this->json(['deleted' => true, 'email' => $email]);
    }

    #[Route('/{id}/quotas', name: 'api_admin_users_quotas', methods: ['PATCH'])]
    public function updateQuotas(
        string $id,
        #[MapRequestPayload] AdminQuotaInput $input,
    ): JsonResponse {
        $user = $this->findUser($id);
        if ($user === null) {
            return $this->json(['error' => 'Utilisateur introuvable.'], Response::HTTP_NOT_FOUND);
        }

        if ($input->resetStorage) {
            $user->setStorageQuotaBytes(null);
        } elseif ($input->storageQuotaBytes !== null) {
            $user->setStorageQuotaBytes($input->storageQuotaBytes);
        }

        if ($input->resetBandwidth) {
            $user->setBandwidthQuotaMonthBytes(null);
        } elseif ($input->bandwidthQuotaMonthBytes !== null) {
            $user->setBandwidthQuotaMonthBytes($input->bandwidthQuotaMonthBytes);
        }

        $this->entityManager->flush();

        return $this->json(['user' => $this->serialize($user)]);
    }

    private function readMessage(Request $request): string
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return '';
        }
        $message = $data['message'] ?? '';

        return is_string($message) ? mb_substr(trim($message), 0, 2000) : '';
    }

    private function moderate(string $id, UserStatus $status, string $mailAction, string $message): JsonResponse
    {
        $user = $this->findUser($id);
        if ($user === null) {
            return $this->json(['error' => 'Utilisateur introuvable.'], Response::HTTP_NOT_FOUND);
        }

        /** @var User $current */
        $current = $this->getUser();
        if ($user->getId()->equals($current->getId()) && $status !== UserStatus::Active) {
            return $this->json(
                ['error' => 'Vous ne pouvez pas vous désactiver ou vous refuser vous-même.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if ($status === UserStatus::Active) {
            $user->setEmailVerifiedAt($user->getEmailVerifiedAt() ?? new \DateTimeImmutable());
            $user->clearEmailVerificationChallenge();
        }

        $user->setStatus($status);
        $this->entityManager->flush();

        try {
            $this->accountMailer->sendModerationNotice($user, $mailAction, $message);
        } catch (\Throwable) {
            // status already applied
        }

        return $this->json(['user' => $this->serialize($user)]);
    }

    private function findUser(string $id): ?User
    {
        try {
            return $this->users->find(Uuid::fromString($id));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @return array{
     *   id: string,
     *   email: string,
     *   createdAt: string,
     *   status: string,
     *   roles: list<string>,
     *   storageQuotaBytes: ?int,
     *   bandwidthQuotaMonthBytes: ?int,
     *   emailVerifiedAt: ?string
     * }
     */
    private function serialize(User $user): array
    {
        return [
            'id' => $user->getId()->toRfc4122(),
            'email' => $user->getEmail(),
            'createdAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'status' => $user->getStatus()->value,
            'roles' => $user->getRoles(),
            'storageQuotaBytes' => $user->getStorageQuotaBytes(),
            'bandwidthQuotaMonthBytes' => $user->getBandwidthQuotaMonthBytes(),
            'emailVerifiedAt' => $user->getEmailVerifiedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
