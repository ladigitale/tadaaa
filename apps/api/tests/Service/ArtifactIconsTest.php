<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ArtifactDocumentValidator;
use App\Service\ArtifactIcons;
use App\Service\ArtifactScriptsCatalog;
use PHPUnit\Framework\TestCase;

final class ArtifactIconsTest extends TestCase
{
    private const DIR = __DIR__.'/../../config/artifacts';

    public function testSearchInFrenchAndEnglish(): void
    {
        $icons = new ArtifactIcons(self::DIR.'/icons.json');
        self::assertSame(['iconoir', 'heroicons'], $icons->libraries());
        self::assertSame('trophy', $icons->search('trophée')[0]);
        self::assertSame('trophy', $icons->search('trophy')[0]);
        self::assertContains('arrow-right', $icons->search('flèche droite'));
        self::assertSame('shuffle', $icons->search('hasard')[0]);
        self::assertContains('heart', $icons->search('hart', 'iconoir', 3));
        self::assertContains('heart', $icons->search('heart', 'heroicons'));
        self::assertSame([], $icons->search('', 'iconoir'));
        self::assertSame([], $icons->search('heart', 'lucide'));
    }

    public function testValidationOfSonicIcon(): void
    {
        $validator = new ArtifactDocumentValidator(self::DIR.'/catalog.json', self::DIR.'/sdui.schema.json', new ArtifactScriptsCatalog(self::DIR.'/scripts-catalog.json'));
        $doc = static fn (array $attributes): array => ['schema' => 'artifacts/1', 'title' => 'I', 'defaultView' => 'v',
            'views' => [['id' => 'v', 'title' => 'V', 'root' => ['nodes' => [['tagName' => 'sonic-icon', 'attributes' => $attributes]]]]]];
        $ok = [
            ['library' => 'iconoir', 'name' => 'trophy', 'size' => 'lg'],
            ['library' => 'heroicons', 'prefix' => 'solid', 'name' => 'heart'],
            ['name' => 'check'],
            ['library' => 'iconoir', 'slot' => 'prefix'],
        ];
        foreach ($ok as $attributes) {
            $r = $validator->validate($doc($attributes));
            self::assertTrue($r['valid'], json_encode($r['errors'], JSON_UNESCAPED_UNICODE));
        }
        $bad = [
            [['library' => 'iconoir', 'name' => 'sparkles'], 'inconnue dans iconoir'],
            [['library' => 'iconoir', 'name' => 'hart'], 'proches : heart'],
            [['name' => 'heart'], 'absente du jeu intégré'],
            [['library' => 'lucide', 'name' => 'heart'], 'iconoir | heroicons'],
            [['library' => 'heroicons', 'prefix' => 'duotone', 'name' => 'heart'], 'outline | solid'],
            [['library' => 'custom', 'customIconLibraryPath' => 'https://evil.example/$name.svg', 'name' => 'x'], 'personnalisée interdite'],
        ];
        foreach ($bad as [$attributes, $message]) {
            $r = $validator->validate($doc($attributes));
            self::assertFalse($r['valid']);
            self::assertStringContainsString($message, json_encode($r['errors'], JSON_UNESCAPED_UNICODE));
        }
        self::assertStringContainsString('find_icons', $validator->mcpCatalogPayload(true)['rules']['icones']);
    }
}
