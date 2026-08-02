<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class AuditLogger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function log(User $owner, string $category, string $action, array $meta = [], ?string $ip = null): void
    {
        $this->entityManager->persist(new AuditLog($owner, $category, $action, $meta, $ip));
        $this->entityManager->flush();
    }

    /**
     * Persist without flushing (caller batches).
     *
     * @param array<string, mixed> $meta
     */
    public function queue(User $owner, string $category, string $action, array $meta = [], ?string $ip = null): void
    {
        $this->entityManager->persist(new AuditLog($owner, $category, $action, $meta, $ip));
    }
}
