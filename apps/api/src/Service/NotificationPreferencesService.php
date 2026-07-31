<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Notification\NotificationEventType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class NotificationPreferencesService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<array{type: string, enabled: bool, default: bool}>
     */
    public function catalogueWithPrefs(User $user): array
    {
        $effective = $this->effectivePrefs($user);
        $defaults = NotificationEventType::defaults();
        $rows = [];
        foreach (NotificationEventType::all() as $type) {
            $rows[] = [
                'type' => $type,
                'enabled' => $effective[$type],
                'default' => $defaults[$type],
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, bool>
     */
    public function effectivePrefs(User $user): array
    {
        $defaults = NotificationEventType::defaults();
        $stored = $user->getNotificationPrefs();
        $effective = $defaults;
        foreach ($stored as $type => $enabled) {
            if (!\is_string($type) || !NotificationEventType::isValid($type)) {
                continue;
            }
            if (!\is_bool($enabled)) {
                continue;
            }
            $effective[$type] = $enabled;
        }

        return $effective;
    }

    public function isEnabled(User $user, string $type): bool
    {
        if (!NotificationEventType::isValid($type)) {
            return false;
        }

        return $this->effectivePrefs($user)[$type];
    }

    /**
     * @param array<string, mixed> $patch
     *
     * @return list<array{type: string, enabled: bool, default: bool}>
     */
    public function update(User $user, array $patch): array
    {
        $stored = $user->getNotificationPrefs();
        foreach ($patch as $type => $enabled) {
            if (!\is_string($type) || !NotificationEventType::isValid($type)) {
                throw new BadRequestHttpException(sprintf('Unknown notification type « %s ».', (string) $type));
            }
            if (!\is_bool($enabled)) {
                throw new BadRequestHttpException(sprintf('Preference « %s » must be a boolean.', $type));
            }
            $stored[$type] = $enabled;
        }
        $user->setNotificationPrefs($stored);
        $this->entityManager->flush();

        return $this->catalogueWithPrefs($user);
    }
}
