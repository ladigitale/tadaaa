<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ArtifactRun;
use App\Service\ArtifactRunService;
use PHPUnit\Framework\TestCase;

final class ArtifactRunTokenTest extends TestCase
{
    private function run(): ArtifactRun
    {
        return (new \ReflectionClass(ArtifactRun::class))->newInstanceWithoutConstructor();
    }

    public function testTokenIsHashedAndMatched(): void
    {
        $run = $this->run();
        $token = $run->issueToken(new \DateTimeImmutable('+1 hour'));

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32}$/', $token);
        self::assertTrue($run->matchesToken($token));
        self::assertFalse($run->matchesToken($token.'x'));
        self::assertFalse($run->matchesToken(''));
        $hash = (new \ReflectionProperty(ArtifactRun::class, 'tokenHash'))->getValue($run);
        self::assertSame(hash('sha256', $token), $hash);
    }

    public function testExpiry(): void
    {
        $run = $this->run();
        $run->issueToken(new \DateTimeImmutable('-1 second'));
        self::assertTrue($run->isExpired());

        $fresh = $this->run();
        $fresh->issueToken(new \DateTimeImmutable('+1 hour'));
        self::assertFalse($fresh->isExpired());
    }

    public function testRunParamsAreAppendedBeforeHash(): void
    {
        $m = new \ReflectionMethod(ArtifactRunService::class, 'withRunParams');
        self::assertSame('https://a.test/somme?run=r1&rt=t1', $m->invoke(null, 'https://a.test/somme', 'r1', 't1'));
        self::assertSame('https://a.test/somme?k=abc&run=r1&rt=t1#vue', $m->invoke(null, 'https://a.test/somme?k=abc#vue', 'r1', 't1'));
    }
}
