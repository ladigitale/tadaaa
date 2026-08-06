<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Dataset;
use App\Entity\DatasetAppDocument;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DatasetAppDocument>
 */
class DatasetAppDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DatasetAppDocument::class);
    }

    public function findOneForDatasetApp(Dataset $dataset, string $appId): ?DatasetAppDocument
    {
        return $this->findOneBy([
            'dataset' => $dataset,
            'appId' => $appId,
        ]);
    }

    /**
     * Datasets the user can access that already have an app document.
     *
     * @return list<array{id: string, name: string, baseId: string, updatedAt: string}>
     */
    public function findSummariesForUserApp(User $user, string $appId): array
    {
        $qb = $this->createQueryBuilder('d')
            ->select('ds.id AS id', 'ds.name AS name', 'ds.baseId AS baseId', 'd.updatedAt AS updatedAt')
            ->innerJoin('d.dataset', 'ds')
            ->leftJoin('App\Entity\DatasetMember', 'm', 'WITH', 'm.dataset = ds AND m.user = :user')
            ->andWhere('d.appId = :appId')
            ->andWhere('ds.owner = :user OR m.id IS NOT NULL')
            ->setParameter('appId', $appId)
            ->setParameter('user', $user)
            ->orderBy('d.updatedAt', 'DESC');

        /** @var list<array{id: \Symfony\Component\Uid\Uuid, name: string, baseId: \Symfony\Component\Uid\Uuid, updatedAt: \DateTimeImmutable}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return array_map(
            static fn (array $row): array => [
                'id' => $row['id']->toRfc4122(),
                'name' => $row['name'],
                'baseId' => $row['baseId']->toRfc4122(),
                'updatedAt' => $row['updatedAt']->format(\DateTimeInterface::ATOM),
            ],
            $rows,
        );
    }
}
