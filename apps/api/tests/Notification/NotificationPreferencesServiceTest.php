<?php

declare(strict_types=1);

namespace App\Tests\Notification;

use App\Entity\User;
use App\Notification\NotificationEventType;
use App\Service\NotificationPreferencesService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class NotificationPreferencesServiceTest extends TestCase
{
    public function testDefaultsWhenEmpty(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $service = new NotificationPreferencesService($em);
        $user = new User('a@example.com');

        $effective = $service->effectivePrefs($user);

        self::assertTrue($effective[NotificationEventType::DATASET_INVITE]);
        self::assertFalse($effective[NotificationEventType::TODO_CREATED]);
        self::assertTrue($service->isEnabled($user, NotificationEventType::TODO_CHECKED));
        self::assertFalse($service->isEnabled($user, NotificationEventType::TODO_CREATED));
    }

    public function testOptOutOverridesDefault(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $service = new NotificationPreferencesService($em);
        $user = new User('a@example.com');
        $user->setNotificationPrefs([
            NotificationEventType::DATASET_INVITE => false,
            NotificationEventType::TODO_CREATED => true,
        ]);

        self::assertFalse($service->isEnabled($user, NotificationEventType::DATASET_INVITE));
        self::assertTrue($service->isEnabled($user, NotificationEventType::TODO_CREATED));
    }

    public function testCatalogueShape(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $service = new NotificationPreferencesService($em);
        $user = new User('a@example.com');
        $rows = $service->catalogueWithPrefs($user);

        self::assertCount(count(NotificationEventType::all()), $rows);
        self::assertSame(NotificationEventType::DATASET_INVITE, $rows[0]['type']);
        self::assertArrayHasKey('enabled', $rows[0]);
        self::assertArrayHasKey('default', $rows[0]);
    }
}
