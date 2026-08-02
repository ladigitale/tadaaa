<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Dataset;
use App\Entity\User;
use App\Entity\WebhookEndpoint;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WebhookEndpoint>
 */
class WebhookEndpointRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebhookEndpoint::class);
    }

    /**
     * @return list<WebhookEndpoint>
     */
    public function findActiveForUser(User $user): array
    {
        /** @var list<WebhookEndpoint> $rows */
        $rows = $this->createQueryBuilder('w')
            ->andWhere('w.owner = :owner')
            ->setParameter('owner', $user)
            ->orderBy('w.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Endpoints actifs pour un dataset : owner du dataset + writers, filtrés dataset/null.
     *
     * @param list<User> $candidateOwners
     *
     * @return list<WebhookEndpoint>
     */
    public function findActiveForDatasetEvent(Dataset $dataset, array $candidateOwners, string $eventType): array
    {
        if ($candidateOwners === []) {
            return [];
        }

        /** @var list<WebhookEndpoint> $rows */
        $rows = $this->createQueryBuilder('w')
            ->andWhere('w.active = true')
            ->andWhere('w.owner IN (:owners)')
            ->andWhere('w.dataset IS NULL OR w.dataset = :dataset')
            ->setParameter('owners', $candidateOwners)
            ->setParameter('dataset', $dataset)
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $rows,
            static fn (WebhookEndpoint $endpoint) => $endpoint->acceptsEvent($eventType),
        ));
    }
}
