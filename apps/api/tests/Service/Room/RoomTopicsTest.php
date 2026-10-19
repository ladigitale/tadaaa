<?php

declare(strict_types=1);

namespace App\Tests\Service\Room;

use App\Service\Room\RoomTopics;
use PHPUnit\Framework\TestCase;

final class RoomTopicsTest extends TestCase
{
    private const ROOM = '0199f0a0-0000-7000-8000-000000000001';
    private const KEY = 'aabbccddeeff00112233445566778899';
    private const MEMBER = '0199f0a0-0000-7000-8000-0000000000aa';

    public function testFourDistinctTopics(): void
    {
        $topics = [
            RoomTopics::data(self::ROOM, self::KEY),
            RoomTopics::ctl(self::ROOM, self::KEY),
            RoomTopics::host(self::ROOM, self::KEY),
            RoomTopics::member(self::ROOM, self::MEMBER),
        ];
        self::assertCount(4, array_unique($topics));
    }

    public function testRoomScopedTopicsCarryTheSecretKey(): void
    {
        foreach ([RoomTopics::data(...), RoomTopics::ctl(...), RoomTopics::host(...)] as $build) {
            self::assertStringContainsString(self::KEY, $build(self::ROOM, self::KEY));
        }
    }

    public function testMemberTopicRevealsNothingAboutTheRoomKey(): void
    {
        // Un invité en attente ne connaît que ce topic : il ne doit pas pouvoir en déduire ceux du salon.
        self::assertStringNotContainsString(self::KEY, RoomTopics::member(self::ROOM, self::MEMBER));
    }

    public function testTopicsOfTwoRoomsNeverCollide(): void
    {
        self::assertNotSame(
            RoomTopics::data(self::ROOM, self::KEY),
            RoomTopics::data('0199f0a0-0000-7000-8000-000000000002', self::KEY),
        );
        self::assertNotSame(
            RoomTopics::data(self::ROOM, self::KEY),
            RoomTopics::data(self::ROOM, 'ffeeddccbbaa99887766554433221100'),
        );
    }

    public function testNoTopicIsAPrefixOfAnother(): void
    {
        // Les sélecteurs Mercure sont des IRI exactes ici ; on vérifie qu'aucun jeton ne couvre un autre topic par préfixe.
        $data = RoomTopics::data(self::ROOM, self::KEY);
        self::assertFalse(str_starts_with(RoomTopics::ctl(self::ROOM, self::KEY), $data));
        self::assertFalse(str_starts_with(RoomTopics::host(self::ROOM, self::KEY), $data));
    }
}
