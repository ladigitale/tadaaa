<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Dataset;
use App\Entity\DatasetAccessRole;
use App\Entity\DatasetMemberRole;
use App\Entity\User;
use App\Repository\DatasetMemberRepository;
use App\Repository\DatasetRepository;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

final class DatasetAccessService
{
    public function __construct(
        private readonly DatasetRepository $datasets,
        private readonly DatasetMemberRepository $members,
    ) {
    }

    public function getRole(User $user, Dataset $dataset): ?DatasetAccessRole
    {
        if ($dataset->getOwner()->getId()->equals($user->getId())) {
            return DatasetAccessRole::Owner;
        }

        $member = $this->members->findOneForUser($dataset, $user);
        if ($member === null) {
            return null;
        }

        return match ($member->getRole()) {
            DatasetMemberRole::Writer => DatasetAccessRole::Writer,
            DatasetMemberRole::Reader => DatasetAccessRole::Reader,
        };
    }

    public function assertCanRead(User $user, Dataset $dataset): DatasetAccessRole
    {
        $role = $this->getRole($user, $dataset);
        if ($role === null) {
            throw new AccessDeniedHttpException('Accès refusé à ce jeu de données.');
        }

        return $role;
    }

    public function assertCanWrite(User $user, Dataset $dataset): DatasetAccessRole
    {
        $role = $this->assertCanRead($user, $dataset);
        if (!$role->canWrite()) {
            throw new AccessDeniedHttpException('Ce jeu est en lecture seule.');
        }

        return $role;
    }

    public function assertIsOwner(User $user, Dataset $dataset): void
    {
        $role = $this->getRole($user, $dataset);
        if ($role === null || !$role->isOwner()) {
            throw new AccessDeniedHttpException('Seul le propriétaire peut effectuer cette action.');
        }
    }

    public function requireAccessibleById(User $user, Uuid $id): Dataset
    {
        $dataset = $this->datasets->find($id);
        if (!$dataset instanceof Dataset) {
            throw new NotFoundHttpException('Jeu de données introuvable.');
        }
        $this->assertCanRead($user, $dataset);

        return $dataset;
    }

    public function requireOwnedById(User $user, Uuid $id): Dataset
    {
        $dataset = $this->datasets->find($id);
        if (!$dataset instanceof Dataset) {
            throw new NotFoundHttpException('Jeu de données introuvable.');
        }
        $this->assertIsOwner($user, $dataset);

        return $dataset;
    }
}
