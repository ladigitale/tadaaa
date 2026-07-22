<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Dataset;
use App\Entity\Todo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Todo>
 */
class TodoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Todo::class);
    }

    public function findOneForDataset(Dataset $dataset, string $id): ?Todo
    {
        return $this->findOneBy(['dataset' => $dataset, 'id' => $id]);
    }

    /**
     * @return list<Todo>
     */
    public function findChangedSince(Dataset $dataset, ?\DateTimeImmutable $since): array
    {
        /** @var list<Todo> $todos */
        $todos = $this->createQueryBuilder('t')
            ->andWhere('t.dataset = :dataset')
            ->setParameter('dataset', $dataset)
            ->getQuery()
            ->getResult();

        if ($since === null) {
            return $todos;
        }

        return array_values(array_filter(
            $todos,
            static function (Todo $todo) use ($since): bool {
                if ($todo->getDeletedAt() !== null && $todo->getDeletedAt() > $since) {
                    return true;
                }

                foreach ($todo->getFieldVersions() as $version) {
                    if (new \DateTimeImmutable($version) > $since) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }
}
