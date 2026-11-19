<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ArtifactRun;
use App\Service\ArtifactRunStreamService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ArtifactRunWatchTokenTest extends TestCase
{
    private static function service(string $secret): ArtifactRunStreamService
    {
        $s = (new \ReflectionClass(ArtifactRunStreamService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(ArtifactRunStreamService::class, 'secret'))->setValue($s, $secret);

        return $s;
    }

    private static function run(): ArtifactRun
    {
        $run = (new \ReflectionClass(ArtifactRun::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(ArtifactRun::class, 'id'))->setValue($run, Uuid::v7());
        (new \ReflectionProperty(ArtifactRun::class, 'streamMeta'))->setValue($run, []);

        return $run;
    }

    public function testSignedWatchTokenIsStableAndScoped(): void
    {
        $svc = self::service('s3cret');
        $a = self::run();
        $legacy = $a->openStream()['watchToken'];
        $t = $svc->watchToken($a);
        self::assertSame($t, $svc->watchToken($a), 'recalculable');
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32}$/', $t);
        self::assertTrue($svc->matchesWatch($a, $t));
        self::assertTrue($svc->matchesWatch($a, $legacy), 'jeton tiré au hasard des runs existants');
        self::assertFalse($svc->matchesWatch($a, ''));
        self::assertFalse($svc->matchesWatch($a, $t.'x'));

        $b = self::run();
        $b->openStream();
        self::assertNotSame($t, $svc->watchToken($b), 'propre à chaque run');
        self::assertFalse($svc->matchesWatch($b, $t));
        self::assertNotSame($t, self::service('autre')->watchToken($a), 'dépend du secret');
    }

    public function testNoStreamNoWatcher(): void
    {
        $svc = self::service('s3cret');
        $run = self::run();
        self::assertFalse($svc->matchesWatch($run, $svc->watchToken($run)));
    }
}
