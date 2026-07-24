<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Dataset;
use App\Entity\User;
use App\Service\DatasetAccessService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProcessorInterface<Dataset, Dataset>
 */
final class DatasetActivateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DatasetAccessService $access,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Dataset
    {
        if (!$data instanceof Dataset) {
            throw new NotFoundHttpException('Jeu introuvable.');
        }

        $user = $this->currentUser();
        $this->access->assertCanRead($user, $data);

        $user->setActiveDataset($data);
        $this->entityManager->flush();

        return $data;
    }

    private function currentUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Authentification requise.');
        }

        return $user;
    }
}
