<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\UserStatus;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
    public function approve(string $id): JsonResponse
    {
        return $this->setStatus($id, UserStatus::Active);
    }

    #[Route('/{id}/reject', name: 'api_admin_users_reject', methods: ['POST'])]
    public function reject(string $id): JsonResponse
    {
        return $this->setStatus($id, UserStatus::Rejected);
    }

    #[Route('/{id}/disable', name: 'api_admin_users_disable', methods: ['POST'])]
    public function disable(string $id): JsonResponse
    {
        return $this->setStatus($id, UserStatus::Disabled);
    }

    private function setStatus(string $id, UserStatus $status): JsonResponse
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

        $user->setStatus($status);
        $this->entityManager->flush();

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
     *   roles: list<string>
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
        ];
    }
}
