<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ArtifactWorkflow;
use App\Entity\Dataset;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ArtifactWorkflow>
 */
class ArtifactWorkflowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArtifactWorkflow::class);
    }

    public function findOneBySlug(Dataset $dataset, string $slug): ?ArtifactWorkflow
    {
        return $this->findOneBy(['dataset' => $dataset, 'slug' => $slug]);
    }

    /** @return list<ArtifactWorkflow> */
    public function findForDataset(Dataset $dataset): array
    {
        /** @var list<ArtifactWorkflow> */
        return $this->findBy(['dataset' => $dataset], ['updatedAt' => 'DESC'], 200);
    }
}
