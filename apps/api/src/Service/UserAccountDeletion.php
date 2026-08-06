<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class UserAccountDeletion
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountMailer $accountMailer,
    ) {
    }

    /**
     * Permanently remove the user and cascaded data. Sends a notice email best-effort.
     */
    public function delete(User $user, string $noticeAction = 'deleted', string $personalMessage = ''): void
    {
        try {
            $this->accountMailer->sendModerationNotice($user, $noticeAction, $personalMessage);
        } catch (\Throwable) {
            // still delete
        }

        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }
}
