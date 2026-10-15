<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AgentConversation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AgentConversation>
 */
class AgentConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentConversation::class);
    }

    public function findOne(User $user, string $profile, string $threadId): ?AgentConversation
    {
        return $this->findOneBy(['user' => $user, 'profile' => $profile, 'threadId' => $threadId]);
    }

    /** @return list<AgentConversation> les plus récentes d'abord */
    public function recent(User $user, string $profile, int $limit = 50): array
    {
        return $this->findBy(['user' => $user, 'profile' => $profile], ['updatedAt' => 'DESC'], $limit);
    }

    public function save(AgentConversation $conversation): void
    {
        $this->getEntityManager()->persist($conversation);
        $this->getEntityManager()->flush();
    }

    public function remove(AgentConversation $conversation): void
    {
        $this->getEntityManager()->remove($conversation);
        $this->getEntityManager()->flush();
    }
}
