<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Dataset;
use App\Entity\User;
use App\Repository\DatasetRepository;
use App\Service\DatasetAccessService;
use App\Util\BaseIdParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * @implements ProcessorInterface<Dataset, Dataset|null>
 */
final class DatasetProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DatasetRepository $datasets,
        private readonly DatasetAccessService $access,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?Dataset
    {
        if (!$data instanceof Dataset) {
            return null;
        }

        $user = $this->currentUser();

        if ($operation instanceof Post) {
            $request = $context['request'] ?? null;
            $baseIdFromPayload = null;
            if ($request instanceof Request) {
                /** @var array<string, mixed>|null $payload */
                $payload = json_decode($request->getContent(), true);
                if (is_string($payload['baseId'] ?? null) && trim($payload['baseId']) !== '') {
                    $baseIdFromPayload = BaseIdParser::parse($payload['baseId']);
                    $data->setBaseId($baseIdFromPayload);
                }
            }

            $existing = $this->datasets->findOneByNameForUser($user, $data->getName());
            if ($existing !== null) {
                // Sync : rattacher le baseId local au jeu cloud homonyme
                if ($baseIdFromPayload !== null) {
                    $existing->setBaseId($baseIdFromPayload);
                    $existing->touch();
                    $this->entityManager->flush();

                    return $existing;
                }

                throw new BadRequestHttpException('Vous avez déjà un jeu avec ce nom.');
            }

            $data->setOwner($user);
            $this->entityManager->persist($data);

            if ($user->getActiveDataset() === null) {
                $user->setActiveDataset($data);
            }

            $this->entityManager->flush();

            return $data;
        }

        if ($operation instanceof Delete) {
            $this->access->assertIsOwner($user, $data);

            if ($this->datasets->countForUser($user) <= 1) {
                throw new BadRequestHttpException('Impossible de supprimer le dernier jeu.');
            }

            if ($user->getActiveDataset()?->getId()->equals($data->getId())) {
                $replacement = $this->datasets->createQueryBuilder('d')
                    ->andWhere('d.owner = :owner')
                    ->andWhere('d.id != :id')
                    ->setParameter('owner', $user)
                    ->setParameter('id', $data->getId())
                    ->setMaxResults(1)
                    ->getQuery()
                    ->getOneOrNullResult();

                $user->setActiveDataset($replacement instanceof Dataset ? $replacement : null);
            }

            $this->entityManager->remove($data);
            $this->entityManager->flush();

            return null;
        }

        if ($operation instanceof Patch) {
            $this->access->assertIsOwner($user, $data);

            $existing = $this->datasets->findOneByNameForUser($user, $data->getName());
            if ($existing !== null && !$existing->getId()->equals($data->getId())) {
                throw new BadRequestHttpException('Vous avez déjà un jeu avec ce nom.');
            }

            if (trim($data->getName()) === '') {
                throw new BadRequestHttpException('Le nom du jeu est requis.');
            }

            $data->touch();
            $this->entityManager->flush();

            return $data;
        }

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
