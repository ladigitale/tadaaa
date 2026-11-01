<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ArtifactAssets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class ArtifactAssetsTest extends TestCase
{
    public function testDetectsTypeFromExtensionAndSignature(): void
    {
        self::assertSame('image/png', ArtifactAssets::detect('a.png', "\x89PNG\r\n\x1a\n".'xx'));
        self::assertSame('image/jpeg', ArtifactAssets::detect('a.jpeg', "\xFF\xD8\xFF\xE0".'xx'));
        self::assertSame('image/webp', ArtifactAssets::detect('a.webp', 'RIFF0000WEBPVP8 '));
        self::assertSame('audio/wav', ArtifactAssets::detect('a.wav', 'RIFF0000WAVEfmt '));
        self::assertSame('audio/ogg', ArtifactAssets::detect('a.ogg', 'OggS'.'x'));
        self::assertSame('audio/mpeg', ArtifactAssets::detect('a.mp3', 'ID3x'));
        self::assertSame('audio/mpeg', ArtifactAssets::detect('b.mp3', "\xFF\xFBxx"));
        self::assertSame('model/gltf-binary', ArtifactAssets::detect('m.glb', 'glTF'.'xxxx'));
        self::assertSame('video/mp4', ArtifactAssets::detect('v.mp4', "\0\0\0\x18ftypisom"));
        self::assertSame('application/json', ArtifactAssets::detect('d.json', '{"a":[1,2]}'));
        self::assertSame('image/svg+xml', ArtifactAssets::detect('i.svg', '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejected(): iterable
    {
        yield 'extension inconnue' => ['a.exe', 'MZ'];
        yield 'sans extension' => ['lisezmoi', 'x'];
        yield 'majuscules' => ['Image.png', "\x89PNG\r\n\x1a\n"];
        yield 'nom avec espace' => ['mon image.png', "\x89PNG\r\n\x1a\n"];
        yield 'png qui est un exécutable' => ['a.png', 'MZ......'];
        yield 'html déguisé en png' => ['a.png', '<html><script>alert(1)</script></html>'];
        yield 'json invalide' => ['d.json', '{oups'];
        yield 'svg sans balise svg' => ['i.svg', '<html></html>'];
        yield 'svg avec script' => ['i.svg', '<svg><script>alert(1)</script></svg>'];
        yield 'svg avec gestionnaire' => ['i.svg', '<svg onload="alert(1)"></svg>'];
        yield 'svg avec javascript:' => ['i.svg', '<svg><a href="javascript:alert(1)"/></svg>'];
        yield 'vide' => ['a.png', ''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejected')]
    public function testRejectsInvalidAssets(string $name, string $bytes): void
    {
        $this->expectException(BadRequestHttpException::class);
        ArtifactAssets::detect($name, $bytes);
    }

    public function testRejectsOversizedAsset(): void
    {
        $this->expectException(BadRequestHttpException::class);
        ArtifactAssets::detect('big.json', '"'.str_repeat('a', ArtifactAssets::MAX_FILE_BYTES).'"');
    }
}
