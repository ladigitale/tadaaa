<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Dataset;
use App\Entity\Tag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tag>
 */
class TagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }

    public function findOneForDataset(Dataset $dataset, string $id): ?Tag
    {
        return $this->findOneBy(['dataset' => $dataset, 'id' => $id]);
    }

    /**
     * @return list<Tag>
     */
    public function findChangedSince(Dataset $dataset, ?\DateTimeImmutable $since): array
    {
        /** @var list<Tag> $tags */
        $tags = $this->createQueryBuilder('t')
            ->andWhere('t.dataset = :dataset')
            ->setParameter('dataset', $dataset)
            ->getQuery()
            ->getResult();

        if ($since === null) {
            return $tags;
        }

        return array_values(array_filter(
            $tags,
            static function (Tag $tag) use ($since): bool {
                if ($tag->getDeletedAt() !== null && $tag->getDeletedAt() > $since) {
                    return true;
                }

                foreach ($tag->getFieldVersions() as $version) {
                    if (new \DateTimeImmutable($version) > $since) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }
}
