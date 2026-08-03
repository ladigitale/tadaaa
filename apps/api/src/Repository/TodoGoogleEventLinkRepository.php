<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Dataset;
use App\Entity\TodoGoogleEventLink;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TodoGoogleEventLink>
 */
class TodoGoogleEventLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TodoGoogleEventLink::class);
    }

    public function findOneForUserTodo(User $user, Dataset $dataset, string $todoId): ?TodoGoogleEventLink
    {
        return $this->findOneBy([
            'user' => $user,
            'dataset' => $dataset,
            'todoId' => $todoId,
        ]);
    }

    public function findOneByGoogleEvent(User $user, string $calendarId, string $eventId): ?TodoGoogleEventLink
    {
        return $this->findOneBy([
            'user' => $user,
            'googleCalendarId' => $calendarId,
            'googleEventId' => $eventId,
        ]);
    }

    /**
     * @return list<TodoGoogleEventLink>
     */
    public function findAllForUser(User $user): array
    {
        /** @var list<TodoGoogleEventLink> $rows */
        $rows = $this->findBy(['user' => $user]);

        return $rows;
    }
}
