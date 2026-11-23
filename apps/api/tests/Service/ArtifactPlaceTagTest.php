<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Artifact;
use App\Service\ArtifactPlaceTagService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class ArtifactPlaceTagTest extends TestCase
{
    private static function svg(string $inner): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256">'.$inner.'</svg>');
    }

    public function testAcceptsSketchExport(): void
    {
        $v = self::svg('<defs><mask id="m"><rect width="9" height="9"/></mask></defs><g mask="url(#m)"><path d="M0 0L9 9" stroke="#ffd23a"/><use href="#m"/></g>'
            .'<metadata id="sonic-sketch-source" data-format="gz64">H4sIA/onAB==</metadata>');
        self::assertSame($v, ArtifactPlaceTagService::cleanAvatar($v));
        self::assertNull(ArtifactPlaceTagService::cleanAvatar(''));
        self::assertStringNotContainsString('metadata', ArtifactPlaceTagService::stripMetadata(ArtifactPlaceTagService::svgOf($v)));
    }

    /** @return iterable<string, array{string}> */
    public static function unsafe(): iterable
    {
        yield 'script' => ['<script>alert(1)</script>'];
        yield 'évènement' => ['<rect onload="alert(1)"/>'];
        yield 'lien externe' => ['<use href="https://x.test/a.svg#b"/>'];
        yield 'xlink externe' => ['<a xlink:href="javascript:alert(1)"><rect/></a>'];
        yield 'image' => ['<image href="#x"/>'];
        yield 'url externe' => ['<rect fill="url(https://x.test/p)"/>'];
        yield 'foreignObject' => ['<foreignObject><div/></foreignObject>'];
        yield 'import' => ['<style>@import "https://x.test/a.css";</style>'];
    }

    /** @dataProvider unsafe */
    public function testRejectsActiveOrExternalContent(string $inner): void
    {
        $this->expectException(BadRequestHttpException::class);
        ArtifactPlaceTagService::cleanAvatar(self::svg($inner));
    }

    public function testRejectsOtherFormatsAndHugeFiles(): void
    {
        foreach (['data:image/png;base64,AAAA', 'https://x.test/a.svg', 'data:image/svg+xml;base64,'.str_repeat('A', 50_000)] as $bad) {
            try {
                ArtifactPlaceTagService::cleanAvatar($bad);
                self::fail('accepté : '.substr($bad, 0, 30));
            } catch (BadRequestHttpException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testDeviceKeyIsHashedPerArtifact(): void
    {
        $a = (new \ReflectionClass(Artifact::class))->newInstanceWithoutConstructor();
        $id = new \ReflectionProperty(Artifact::class, 'id');
        $id->setValue($a, \Symfony\Component\Uid\Uuid::v7());
        $b = (new \ReflectionClass(Artifact::class))->newInstanceWithoutConstructor();
        $id->setValue($b, \Symfony\Component\Uid\Uuid::v7());
        $key = str_repeat('aZ_-', 6);
        self::assertNull(ArtifactPlaceTagService::deviceHash($a, null));
        self::assertNull(ArtifactPlaceTagService::deviceHash($a, 'court'));
        self::assertNull(ArtifactPlaceTagService::deviceHash($a, str_repeat('é', 30)));
        self::assertSame(64, \strlen((string) ArtifactPlaceTagService::deviceHash($a, $key)));
        self::assertNotSame(ArtifactPlaceTagService::deviceHash($a, $key), ArtifactPlaceTagService::deviceHash($b, $key));
    }
}
