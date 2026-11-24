<?php

declare(strict_types=1);

namespace App\Tests\Notification;

use App\Entity\User;
use App\Notification\NotificationEventType;
use App\Service\NotificationPreferencesService;
use App\Service\ProdDeploy;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class NotificationPreferencesServiceTest extends TestCase
{
    public function testDefaultsWhenEmpty(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $service = new NotificationPreferencesService($em, new ProdDeploy('', 'boss@example.com'));
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
        $service = new NotificationPreferencesService($em, new ProdDeploy('', 'boss@example.com'));
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
        $service = new NotificationPreferencesService($em, new ProdDeploy('', 'boss@example.com'));
        $user = new User('a@example.com');
        $rows = $service->catalogueWithPrefs($user);

        self::assertCount(count(NotificationEventType::all()) - count(NotificationEventType::adminOnly()), $rows);
        self::assertNotContains(NotificationEventType::ADMIN_REPORT, array_column($rows, 'type'));
        self::assertSame(NotificationEventType::DATASET_INVITE, $rows[0]['type']);
        self::assertArrayHasKey('enabled', $rows[0]);
        self::assertArrayHasKey('default', $rows[0]);
    }

    public function testSuperAdminSeesAdminTypes(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $service = new NotificationPreferencesService($em, new ProdDeploy('', 'boss@example.com'));
        $boss = new User('boss@example.com');
        $boss->setRoles(['ROLE_ADMIN']);
        $types = array_column($service->catalogueWithPrefs($boss), 'type');
        self::assertContains(NotificationEventType::ADMIN_REPORT, $types);
        self::assertContains(NotificationEventType::ADMIN_ACCOUNT_REQUEST, $types);
        // ROLE_ADMIN seul (pas dans OPS_DEPLOY_USERS) : pas super admin.
        $admin = new User('other@example.com');
        $admin->setRoles(['ROLE_ADMIN']);
        self::assertNotContains(NotificationEventType::ADMIN_REPORT, array_column($service->catalogueWithPrefs($admin), 'type'));
    }
}
