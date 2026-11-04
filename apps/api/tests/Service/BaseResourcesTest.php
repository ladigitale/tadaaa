<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BaseResources;
use App\Service\Resources;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class BaseResourcesTest extends TestCase
{
    private function base(): BaseResources
    {
        return new BaseResources(__DIR__.'/../../config/artifacts/library.json');
    }

    public function testLibraryIsLoadedWithSourcesAndHashes(): void
    {
        $plasma = $this->base()->get('shader/plasma');
        self::assertNotNull($plasma);
        self::assertStringContainsString('mainImage', $plasma['source']);
        $summary = BaseResources::summary($plasma);
        self::assertSame('base:shader/plasma', $summary['ref']);
        self::assertSame('shader', $summary['kind']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $summary['sha256']);
        self::assertArrayNotHasKey('source', $summary);
        self::assertNull($this->base()->get('shader/inconnu'));
    }

    public function testMissingFileGivesEmptyLibrary(): void
    {
        self::assertSame([], (new BaseResources('/nulle/part.json'))->all());
    }

    public function testParseRef(): void
    {
        self::assertSame(['mine', 'mon-shader'], Resources::parseRef('mon-shader'));
        self::assertSame(['mine', 'mon-shader'], Resources::parseRef('mine:mon-shader'));
        self::assertSame(['base', 'shader/plasma'], Resources::parseRef('base:shader/plasma'));
        foreach (['base:shader', 'base:../etc', 'MAJ', 'mine:'] as $bad) {
            try {
                Resources::parseRef($bad);
                self::fail("« $bad » aurait dû être refusée.");
            } catch (BadRequestHttpException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame('100\\%\\_x\\\\', Resources::escapeLike('100%_x\\'));
    }
}
