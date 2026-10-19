<?php

declare(strict_types=1);

namespace App\Tests\Service\Room;

use App\Entity\ArtifactRoomAccess;
use App\Service\Room\JoinDecision;
use App\Service\Room\RoomAccessPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RoomAccessPolicyTest extends TestCase
{
    public function testOpenRoomAdmitsDirectly(): void
    {
        self::assertSame(
            JoinDecision::Admit,
            RoomAccessPolicy::decideJoin(ArtifactRoomAccess::Open, null, null, 1, 0, 8),
        );
    }

    public function testApprovalRoomQueuesTheRequest(): void
    {
        self::assertSame(
            JoinDecision::Pending,
            RoomAccessPolicy::decideJoin(ArtifactRoomAccess::Approval, null, null, 1, 0, 8),
        );
    }

    public function testFullRoomRefusesEvenBeforeQueueing(): void
    {
        self::assertSame(JoinDecision::Full, RoomAccessPolicy::decideJoin(ArtifactRoomAccess::Open, null, null, 8, 0, 8));
        self::assertSame(JoinDecision::Full, RoomAccessPolicy::decideJoin(ArtifactRoomAccess::Approval, null, null, 8, 0, 8));
    }

    public function testPendingQueueIsBounded(): void
    {
        self::assertSame(
            JoinDecision::TooManyPending,
            RoomAccessPolicy::decideJoin(ArtifactRoomAccess::Approval, null, null, 1, RoomAccessPolicy::MAX_PENDING, 8),
        );
    }

    #[DataProvider('codeAttempts')]
    public function testCodeIsRequiredWhenTheRoomHasOne(?string $given, JoinDecision $expected): void
    {
        self::assertSame(
            $expected,
            RoomAccessPolicy::decideJoin(ArtifactRoomAccess::Open, 'coetdju', $given, 1, 0, 8),
        );
    }

    /** @return iterable<string, array{0: ?string, 1: JoinDecision}> */
    public static function codeAttempts(): iterable
    {
        yield 'exact code' => ['coetdju', JoinDecision::Admit];
        yield 'typed with case and spaces' => [' CoEt-Dju ', JoinDecision::Admit];
        yield 'wrong code' => ['coetdjv', JoinDecision::WrongCode];
        yield 'missing code' => [null, JoinDecision::WrongCode];
        yield 'empty code' => ['', JoinDecision::WrongCode];
        yield 'only punctuation' => ['--', JoinDecision::WrongCode];
    }

    public function testWrongCodeIsReportedBeforeFullness(): void
    {
        // Ne pas révéler qu'un salon est plein à quelqu'un qui n'a pas le code.
        self::assertSame(
            JoinDecision::WrongCode,
            RoomAccessPolicy::decideJoin(ArtifactRoomAccess::Open, 'coetdju', 'nope', 8, 0, 8),
        );
    }

    public function testCodeAndApprovalCombine(): void
    {
        self::assertSame(
            JoinDecision::Pending,
            RoomAccessPolicy::decideJoin(ArtifactRoomAccess::Approval, 'coetdju', 'coetdju', 1, 0, 8),
        );
        self::assertSame(
            JoinDecision::WrongCode,
            RoomAccessPolicy::decideJoin(ArtifactRoomAccess::Approval, 'coetdju', 'x', 1, 0, 8),
        );
    }

    public function testRoomsWithACodeAreUnlistedByDefault(): void
    {
        self::assertTrue(RoomAccessPolicy::defaultListed(null));
        self::assertFalse(RoomAccessPolicy::defaultListed('coetdju'));
    }
}
