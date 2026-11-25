<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ArtifactAppIcon;
use App\Service\ArtifactDocumentValidator;
use App\Service\ArtifactPageImage;
use App\Service\ArtifactScriptsCatalog;
use PHPUnit\Framework\TestCase;

final class ArtifactPageImageTest extends TestCase
{
    public function testValidation(): void
    {
        self::assertSame([], ArtifactPageImage::validate(['src' => '@asset:apercu.png', 'alt' => 'Le jeu']));
        self::assertSame([], ArtifactPageImage::validate(['view' => 'jeu', 'wait' => 2000]));
        self::assertSame([], ArtifactPageImage::validate(['mode' => 'carte']));
        self::assertNotSame([], ArtifactPageImage::validate('apercu.png'));
        self::assertNotSame([], ArtifactPageImage::validate(['src' => '@asset:son.mp3']));
        self::assertNotSame([], ArtifactPageImage::validate(['src' => 'http://exemple.org/a.png']));
        self::assertNotSame([], ArtifactPageImage::validate(['wait' => 9000]));
        self::assertNotSame([], ArtifactPageImage::validate(['mode' => 'video']));
        self::assertSame('/image/x', ArtifactPageImage::validate(['x' => 1])[0]['path']);
    }

    public function testFromDocument(): void
    {
        $image = ArtifactPageImage::fromDocument(['image' => ['src' => 'https://api.exemple.org/a.png', 'view' => 'jeu', 'wait' => 99999]]);
        self::assertSame('https://api.exemple.org/a.png', $image['src']);
        self::assertSame('jeu', $image['view']);
        self::assertSame(5000, $image['wait']);
        self::assertSame('capture', $image['mode']);
        // @asset non résolu (asset absent) : pas d'image imposée.
        self::assertNull(ArtifactPageImage::fromDocument(['image' => ['src' => '@asset:a.png']])['src']);
        self::assertSame('capture', ArtifactPageImage::fromDocument([])['mode']);
    }

    public function testCardIsAValidDocument(): void
    {
        $dir = \dirname(__DIR__, 2).'/config/artifacts';
        $validator = new ArtifactDocumentValidator($dir.'/catalog.json', $dir.'/sdui.schema.json', new ArtifactScriptsCatalog($dir.'/scripts-catalog.json'));
        $icon = ArtifactAppIcon::fromDocument(['icon' => ['emoji' => '🐙', 'background' => '#0b3d5c']]);
        foreach ([false, true] as $narrow) {
            $card = ArtifactPageImage::cardDocument('Abysse', 'Une plongée sans fond.', $icon, $narrow);
            self::assertSame(['valid' => true, 'errors' => []], $validator->validate($card));
        }
        $json = (string) json_encode(ArtifactPageImage::cardDocument(str_repeat('Titre ', 30), '', $icon), \JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('…', $json);
        self::assertStringContainsString('🐙', $json);
    }

    public function testDocumentAcceptsImage(): void
    {
        $dir = \dirname(__DIR__, 2).'/config/artifacts';
        $validator = new ArtifactDocumentValidator($dir.'/catalog.json', $dir.'/sdui.schema.json', new ArtifactScriptsCatalog($dir.'/scripts-catalog.json'));
        $doc = ArtifactPageImage::cardDocument('Essai', '', ArtifactAppIcon::fromDocument(null));
        $doc['image'] = ['view' => 'carte', 'wait' => 500];
        self::assertTrue($validator->validate($doc)['valid']);
        $doc['image'] = ['src' => 'apercu.png'];
        self::assertSame('/image/src', $validator->validate($doc)['errors'][0]['path']);
    }
}
