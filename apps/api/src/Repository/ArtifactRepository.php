<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Artifact;
use App\Entity\Dataset;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Artifact>
 */
class ArtifactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Artifact::class);
    }

    public function findOneBySlug(string $slug): ?Artifact
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    public function slugExists(string $slug, ?Artifact $except = null): bool
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.slug = :slug')
            ->setParameter('slug', $slug);
        if ($except !== null) {
            $qb->andWhere('a.id != :id')->setParameter('id', $except->getId(), 'uuid');
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * Artifacts on datasets the user can read, optionally filtered by dataset.
     *
     * @return list<Artifact>
     */
    public function findAccessibleForUser(User $user, ?Dataset $dataset = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->innerJoin('a.dataset', 'ds')
            ->leftJoin('App\Entity\DatasetMember', 'm', 'WITH', 'm.dataset = ds AND m.user = :user')
            ->andWhere('ds.owner = :user OR m.id IS NOT NULL')
            ->setParameter('user', $user)
            ->orderBy('a.updatedAt', 'DESC');

        if ($dataset !== null) {
            $qb->andWhere('a.dataset = :dataset')->setParameter('dataset', $dataset);
        }

        /** @var list<Artifact> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }
}
