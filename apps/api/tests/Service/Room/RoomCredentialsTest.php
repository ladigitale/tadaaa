<?php

declare(strict_types=1);

namespace App\Tests\Service\Room;

use App\Entity\Artifact;
use App\Entity\ArtifactRoom;
use App\Entity\ArtifactRoomAccess;
use App\Entity\ArtifactRoomMember;
use App\Entity\ArtifactRoomMemberStatus;
use App\Service\Room\RoomCredentials;
use App\Service\Room\RoomTopics;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\Jwt\LcobucciFactory;

/**
 * Droits Mercure par rôle, vérifiés sur de vrais JWT signés (pas sur des doubles) :
 * c'est la seule barrière entre les participants d'un salon et les topics des autres.
 */
final class RoomCredentialsTest extends TestCase
{
    private LcobucciFactory $factory;
    private ArtifactRoom $room;

    protected function setUp(): void
    {
        $this->factory = new LcobucciFactory('test-secret-test-secret-test-secret-32b');
        $artifact = (new \ReflectionClass(Artifact::class))->newInstanceWithoutConstructor();
        $this->room = new ArtifactRoom($artifact, 'Salon', ArtifactRoomAccess::Open, null, true, 8, new \DateTimeImmutable('+1 hour'));
    }

    private function member(bool $host, ArtifactRoomMemberStatus $status): ArtifactRoomMember
    {
        return new ArtifactRoomMember($this->room, 'Zoé', $host, $status, hash('sha256', 'k'));
    }

    /** @return array<string, mixed> claims du JWT (signature non vérifiée ici : on lit ce qui est promis) */
    private function claims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        self::assertCount(3, $parts);
        /** @var array<string, mixed> $claims */
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true, 512, \JSON_THROW_ON_ERROR);

        return $claims;
    }

    public function testPendingGuestCanOnlyReadItsOwnTopicAndHasNoPublishClaimAtAll(): void
    {
        $member = $this->member(false, ArtifactRoomMemberStatus::Pending);
        $creds = RoomCredentials::build($this->factory, 'https://hub.test/.well-known/mercure', $this->room, $member);
        $mercure = $this->claims($creds['token'])['mercure'];

        self::assertSame([RoomTopics::member($this->room->getId()->toRfc4122(), $member->getId()->toRfc4122())], $mercure['subscribe']);
        // « absent » et pas « liste vide » : un claim présent mais vide peut encore autoriser des updates publics.
        self::assertArrayNotHasKey('publish', $mercure);
        self::assertSame([], $creds['publish']);
        self::assertSame(['member'], array_keys($creds['topics']));
    }

    public function testActiveGuestReadsDataAndCtlButPublishesOnDataOnly(): void
    {
        $member = $this->member(false, ArtifactRoomMemberStatus::Active);
        $creds = RoomCredentials::build($this->factory, 'https://hub.test/.well-known/mercure', $this->room, $member);
        $mercure = $this->claims($creds['token'])['mercure'];
        $id = $this->room->getId()->toRfc4122();
        $key = $this->room->getTopicKey();

        self::assertEqualsCanonicalizing([
            RoomTopics::member($id, $member->getId()->toRfc4122()),
            RoomTopics::data($id, $key),
            RoomTopics::ctl($id, $key),
        ], $mercure['subscribe']);
        self::assertSame([RoomTopics::data($id, $key)], $mercure['publish']);
    }

    public function testNobodyCanPublishOnServerOnlyTopics(): void
    {
        $id = $this->room->getId()->toRfc4122();
        $key = $this->room->getTopicKey();
        foreach ([false, true] as $host) {
            $member = $this->member($host, ArtifactRoomMemberStatus::Active);
            $publish = $this->claims(RoomCredentials::build($this->factory, 'https://hub.test', $this->room, $member)['token'])['mercure']['publish'];

            self::assertNotContains(RoomTopics::ctl($id, $key), $publish, 'ctl est réservé au serveur (roster, kick, closed)');
            self::assertNotContains(RoomTopics::host($id, $key), $publish, 'host est réservé au serveur (join-request)');
            self::assertNotContains(RoomTopics::member($id, $member->getId()->toRfc4122()), $publish);
        }
    }

    public function testOnlyTheHostReadsJoinRequests(): void
    {
        $id = $this->room->getId()->toRfc4122();
        $hostTopic = RoomTopics::host($id, $this->room->getTopicKey());

        $guest = $this->member(false, ArtifactRoomMemberStatus::Active);
        $host = $this->member(true, ArtifactRoomMemberStatus::Active);

        self::assertNotContains($hostTopic, $this->claims(RoomCredentials::build($this->factory, 'https://hub.test', $this->room, $guest)['token'])['mercure']['subscribe']);
        self::assertContains($hostTopic, $this->claims(RoomCredentials::build($this->factory, 'https://hub.test', $this->room, $host)['token'])['mercure']['subscribe']);
    }

    public function testTokenExpiresAfterTheTtl(): void
    {
        $member = $this->member(false, ArtifactRoomMemberStatus::Active);
        $creds = RoomCredentials::build($this->factory, 'https://hub.test', $this->room, $member, 120);
        $exp = $this->claims($creds['token'])['exp'];

        self::assertSame(120, $creds['expiresIn']);
        self::assertEqualsWithDelta(time() + 120, $exp, 5);
    }

    public function testTwoMembersNeverShareTheirPersonalTopic(): void
    {
        $a = RoomCredentials::build($this->factory, 'https://hub.test', $this->room, $this->member(false, ArtifactRoomMemberStatus::Active));
        $b = RoomCredentials::build($this->factory, 'https://hub.test', $this->room, $this->member(false, ArtifactRoomMemberStatus::Active));

        self::assertNotSame($a['topics']['member'], $b['topics']['member']);
    }
}
