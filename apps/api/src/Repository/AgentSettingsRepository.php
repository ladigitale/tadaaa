<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AgentSettings;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AgentSettings>
 */
class AgentSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentSettings::class);
    }

    public function findForUser(User $user): ?AgentSettings
    {
        return $this->findOneBy(['user' => $user]);
    }

    public function save(AgentSettings $settings): void
    {
        $this->getEntityManager()->persist($settings);
        $this->getEntityManager()->flush();
    }
}
