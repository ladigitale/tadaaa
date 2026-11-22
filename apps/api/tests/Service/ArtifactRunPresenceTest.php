<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Artifact;
use App\Entity\ArtifactRun;
use App\Entity\ArtifactRunLink;
use App\Service\ArtifactRunPresenceService;
use App\Service\ArtifactStream;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ArtifactRunPresenceTest extends TestCase
{
    private static function run(string $slug, ?int $seen, string $status = ArtifactRun::STATUS_PENDING): ArtifactRun
    {
        $artifact = (new \ReflectionClass(Artifact::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(Artifact::class, 'slug'))->setValue($artifact, $slug);
        $run = (new \ReflectionClass(ArtifactRun::class))->newInstanceWithoutConstructor();
        foreach (['id' => Uuid::v7(), 'artifact' => $artifact, 'status' => $status, 'streamMeta' => $seen === null ? [] : ['seen' => $seen], 'expiresAt' => new \DateTimeImmutable('+1 day')] as $k => $v) {
            (new \ReflectionProperty(ArtifactRun::class, $k))->setValue($run, $v);
        }

        return $run;
    }

    public function testMembersOnlineInLinkOrder(): void
    {
        $now = 1_000_000;
        $host = self::run('regie', $now);
        $a = self::run('ecran', $now - 2_000);
        $b = self::run('ecran', $now - 60_000);          // silencieux depuis une minute
        $c = self::run('telecommande', $now - 1_000);
        $d = self::run('ecran', 0);                      // parti (bye)
        $e = self::run('ecran', $now, ArtifactRun::STATUS_COMPLETED);
        $links = [
            new ArtifactRunLink($a, $host, null, null),
            new ArtifactRunLink($host, $a, 'data', null), // aller-retour : un seul membre
            new ArtifactRunLink($b, $host, null, null),
            new ArtifactRunLink($host, $c, 'data', null), // relié dans l'autre sens seulement
            new ArtifactRunLink($d, $host, null, null),
            new ArtifactRunLink($e, $host, null, null),
        ];
        $members = ArtifactRunPresenceService::members($links, $host, $now);
        self::assertSame([$a->getId()->toRfc4122(), $c->getId()->toRfc4122()], array_column($members, 'runId'));
        self::assertSame([1, 3], array_column($members, 'index'), 'rang stable dans l’ordre des liens');
        self::assertSame('telecommande', $members[1]['slug']);
        self::assertSame($now - 1_000, $members[1]['seen']);
    }

    public function testIndexStaysStableWhenADeviceLeaves(): void
    {
        $now = 1_000_000;
        $host = self::run('regie', $now);
        $a = self::run('ecran', $now - 1_000);
        $b = self::run('ecran', $now - 1_000);
        $c = self::run('ecran', $now - 1_000);
        $la = new ArtifactRunLink($a, $host, null, null);
        $lb = new ArtifactRunLink($b, $host, null, null);
        $lc = new ArtifactRunLink($c, $host, null, null);
        // b retiré : son lien n'est plus actif, c garde le rang 3.
        $members = ArtifactRunPresenceService::members([$la, $lc], $host, $now, [$la, $lb, $lc], [$c->getId()->toRfc4122() => 'bleu']);
        self::assertSame([1, 3], array_column($members, 'index'));
        self::assertSame([null, 'bleu'], array_column($members, 'role'));
    }

    public function testDesksAreListedApart(): void
    {
        $now = 1_000_000;
        $host = self::run('regie', $now);
        $desk = self::run('guichet', $now - 1_000);
        $a = self::run('ecran', $now - 1_000);
        $ld = new ArtifactRunLink($host, $desk, null, null);
        $la = new ArtifactRunLink($a, $host, null, null);
        $roles = [$desk->getId()->toRfc4122() => '_guichet', $a->getId()->toRfc4122() => 'rouge'];
        $members = ArtifactRunPresenceService::members([$ld, $la], $host, $now, [$ld, $la], $roles);
        self::assertSame([$a->getId()->toRfc4122()], array_column($members, 'runId'));
        self::assertSame([1], array_column($members, 'index'), 'le guichet ne prend pas de rang');
        $desks = ArtifactRunPresenceService::members([$ld, $la], $host, $now, [$ld, $la], $roles, true);
        self::assertSame([$desk->getId()->toRfc4122()], array_column($desks, 'runId'));
    }

    public function testHeartbeatMessages(): void
    {
        foreach (['alive', 'bye'] as $type) {
            [$msg, $errors] = ArtifactStream::normalizeOut((object) ['type' => $type]);
            self::assertSame([], $errors);
            self::assertSame($type, $msg->type);
        }
    }
}
