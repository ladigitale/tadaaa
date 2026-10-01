<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ArtifactDocumentValidator;
use App\Service\ArtifactScriptsCatalog;
use PHPUnit\Framework\TestCase;

final class ArtifactDocumentValidatorTest extends TestCase
{
    private ArtifactDocumentValidator $validator;

    protected function setUp(): void
    {
        $catalog = [
            'safeHtmlTags' => ['div', 'span', 'p', 'a', 'img'],
            'components' => [
                ['name' => 'sonic-button'],
                ['name' => 'sonic-badge'],
                ['name' => 'sonic-caption'],
            ],
        ];
        $scripts = [
            'maxScriptsPerDocument' => 4,
            'cdnAllowlist' => ['https://cdn.jsdelivr.net/'],
            'libraries' => [
                'chartjs' => [
                    'category' => 'charts',
                    'label' => 'Chart.js',
                    'src' => 'https://cdn.jsdelivr.net/npm/chart.js@4.4.8/dist/chart.umd.min.js',
                    'global' => 'Chart',
                ],
                'leaflet' => [
                    'category' => 'maps',
                    'label' => 'Leaflet',
                    'src' => 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js',
                    'css' => ['https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css'],
                    'global' => 'L',
                ],
            ],
        ];
        $dir = sys_get_temp_dir().'/artifacts-test-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/catalog.json', json_encode($catalog));
        file_put_contents($dir.'/sdui.schema.json', '{}');
        file_put_contents($dir.'/scripts-catalog.json', json_encode($scripts));
        $scriptsCatalog = new ArtifactScriptsCatalog($dir.'/scripts-catalog.json');
        $this->validator = new ArtifactDocumentValidator(
            $dir.'/catalog.json',
            $dir.'/sdui.schema.json',
            $scriptsCatalog,
            $catalog,
        );
    }

    public function testValidMinimalDocument(): void
    {
        $doc = $this->minimalDoc();
        $result = $this->validator->validate($doc);
        self::assertTrue($result['valid'], json_encode($result['errors']));
    }

    public function testRejectsUnknownComponent(): void
    {
        $doc = $this->minimalDoc();
        $doc['views'][0]['root']['nodes'][0]['tagName'] = 'evil-widget';
        $result = $this->validator->validate($doc);
        self::assertFalse($result['valid']);
        self::assertStringContainsString('liste blanche', $result['errors'][0]['message']);
    }

    public function testRejectsScriptTag(): void
    {
        $doc = $this->minimalDoc();
        $doc['views'][0]['root']['nodes'][0]['tagName'] = 'script';
        $result = $this->validator->validate($doc);
        self::assertFalse($result['valid']);
    }

    public function testRejectsInnerHtml(): void
    {
        $doc = $this->minimalDoc();
        $doc['views'][0]['root']['nodes'][0]['innerHTML'] = '<b>x</b>';
        $result = $this->validator->validate($doc);
        self::assertFalse($result['valid']);
        self::assertTrue(
            (bool) array_filter($result['errors'], static fn (array $e): bool => str_contains($e['message'], 'innerHTML')),
        );
    }

    public function testRejectsJavascriptUrl(): void
    {
        $doc = $this->minimalDoc();
        $doc['views'][0]['root']['nodes'][0] = [
            'tagName' => 'a',
            'attributes' => ['href' => 'javascript:alert(1)'],
        ];
        $result = $this->validator->validate($doc);
        self::assertFalse($result['valid']);
    }

    public function testRejectsJsAssetArray(): void
    {
        $doc = $this->minimalDoc();
        $doc['views'][0]['root']['js'] = ['https://evil.example/x.js'];
        $result = $this->validator->validate($doc);
        self::assertFalse($result['valid']);
    }

    public function testRejectsBadDefaultView(): void
    {
        $doc = $this->minimalDoc();
        $doc['defaultView'] = 'missing';
        $result = $this->validator->validate($doc);
        self::assertFalse($result['valid']);
    }

    public function testAcceptsWhitelistedScripts(): void
    {
        $doc = $this->minimalDoc();
        $doc['scripts'] = ['chartjs', 'leaflet'];
        $result = $this->validator->validate($doc);
        self::assertTrue($result['valid'], json_encode($result['errors']));
    }

    public function testRejectsUnknownScriptId(): void
    {
        $doc = $this->minimalDoc();
        $doc['scripts'] = ['evil-lib'];
        $result = $this->validator->validate($doc);
        self::assertFalse($result['valid']);
        self::assertTrue(
            (bool) array_filter($result['errors'], static fn (array $e): bool => str_contains($e['message'], 'hors catalogue')),
        );
    }

    /** @return array<string, mixed> */
    private function minimalDoc(): array
    {
        return [
            'schema' => 'artifacts/1',
            'title' => 'Demo',
            'defaultView' => 'home',
            'views' => [
                [
                    'id' => 'home',
                    'title' => 'Accueil',
                    'root' => [
                        'nodes' => [
                            ['tagName' => 'div', 'attributes' => ['class' => 'p-4']],
                        ],
                    ],
                ],
            ],
        ];
    }
}
