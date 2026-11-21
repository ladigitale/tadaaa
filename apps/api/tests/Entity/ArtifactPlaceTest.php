<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Artifact;
use App\Entity\ArtifactPlace;
use PHPUnit\Framework\TestCase;

final class ArtifactPlaceTest extends TestCase
{
    private function place(): ArtifactPlace
    {
        $artifact = (new \ReflectionClass(Artifact::class))->newInstanceWithoutConstructor();

        return new ArtifactPlace($artifact, 'balises', 47.3, 0.6, ['message' => 'salut', 'ref' => ['n' => 0]], [], 40, 'hash', 'ip');
    }

    public function testKeepsTheMostRecentReplacementSurfaces(): void
    {
        $p = $this->place();
        for ($i = 1; $i <= 6; ++$i) {
            self::assertNotNull($p->addRef(['n' => $i], 4, 100000));
        }
        $refs = $p->getBody()['refs'];
        self::assertSame([3, 4, 5, 6], array_column($refs, 'n'));
        self::assertSame(['n' => 0], $p->getBody()['ref'], 'la surface d’origine reste');
    }

    public function testDropsOlderSurfacesToFitAndRefusesOneTooBig(): void
    {
        $p = $this->place();
        $p->addRef(['pad' => str_repeat('a', 300)], 4, 2000);
        $p->addRef(['pad' => str_repeat('b', 300)], 4, 2000);
        $size = $p->addRef(['pad' => str_repeat('c', 300)], 4, 800);
        self::assertNotNull($size);
        self::assertLessThanOrEqual(800, $size);
        self::assertSame($size, $p->getSizeBytes());
        self::assertNull($p->addRef(['pad' => str_repeat('d', 5000)], 4, 800));
    }
}
