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
                ['name' => 'sonic-shader'],
                ['name' => 'sonic-3d'],
                ['name' => 'sonic-jsonata'],
                ['name' => 'sonic-hugging-face-infer'],
                ['name' => 'sonic-store'],
                ['name' => 'sonic-matrix'],
                ['name' => 'sonic-sound'],
                ['name' => 'sonic-sfx'],
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

    public function testAcceptsSonicShader(): void
    {
        $doc = $this->minimalDoc();
        $doc['views'][0]['root']['nodes'][0] = [
            'tagName' => 'sonic-shader',
            'attributes' => [
                'image' => 'void mainImage(out vec4 o, in vec2 p){o=vec4(0.2,0.4,0.8,1.0);}',
                'class' => 'w-full h-64',
            ],
        ];
        $result = $this->validator->validate($doc);
        self::assertTrue($result['valid'], json_encode($result['errors']));
    }

    public function testRejectsJavascriptInShaderAttr(): void
    {
        $doc = $this->minimalDoc();
        $doc['views'][0]['root']['nodes'][0] = [
            'tagName' => 'sonic-shader',
            'attributes' => ['image' => 'javascript:alert(1)'],
        ];
        $result = $this->validator->validate($doc);
        self::assertFalse($result['valid']);
    }

    public function testRejectsOversizedShader(): void
    {
        $doc = $this->minimalDoc();
        $doc['views'][0]['root']['nodes'][0] = [
            'tagName' => 'sonic-shader',
            'attributes' => ['image' => str_repeat('x', 33 * 1024)],
        ];
        $result = $this->validator->validate($doc);
        self::assertFalse($result['valid']);
    }

    public function testAcceptsSonic3dHttpsAllowlisted(): void
    {
        $doc = $this->minimalDoc();
        $doc['views'][0]['root']['nodes'][0] = [
            'tagName' => 'sonic-3d',
            'attributes' => ['src' => 'https://cdn.jsdelivr.net/gh/mrdoob/three.js@r180/examples/models/gltf/Duck/glTF/Duck.gltf'],
        ];
        $result = $this->validator->validate($doc);
        self::assertTrue($result['valid'], json_encode($result['errors']));
    }

    public function testRejectsSonic3dHttp(): void
    {
        $doc = $this->minimalDoc();
        $doc['views'][0]['root']['nodes'][0] = [
            'tagName' => 'sonic-3d',
            'attributes' => ['src' => 'http://example.com/model.glb'],
        ];
        $result = $this->validator->validate($doc);
        self::assertFalse($result['valid']);
    }

    public function testAcceptsHuggingFaceModelId(): void
    {
        $doc = $this->minimalDoc();
        $doc['views'][0]['root']['nodes'][0] = [
            'tagName' => 'sonic-hugging-face-infer',
            'attributes' => [
                'model' => 'Xenova/distilbert-base-uncased-finetuned-sst-2-english',
                'inputProvider' => 'text',
                'outputProvider' => 'out',
            ],
        ];
        $result = $this->validator->validate($doc);
        self::assertTrue($result['valid'], json_encode($result['errors']));
    }

    public function testRejectsBadHuggingFaceModel(): void
    {
        $doc = $this->minimalDoc();
        $doc['views'][0]['root']['nodes'][0] = [
            'tagName' => 'sonic-hugging-face-infer',
            'attributes' => ['model' => 'https://evil.example/model'],
        ];
        $result = $this->validator->validate($doc);
        self::assertFalse($result['valid']);
    }

    public function testAcceptsSonicStoreAndMatrix(): void
    {
        $doc = $this->minimalDoc();
        $doc['views'][0]['root']['nodes'] = [
            [
                'tagName' => 'sonic-store',
                'attributes' => [
                    'id' => 'game',
                    'initial' => '{"score":0}',
                    'reducer' => '$merge([$state, {score: $state.score + 1}])',
                ],
            ],
            [
                'tagName' => 'sonic-matrix',
                'attributes' => ['dataProvider' => 'game', 'key' => 'grid'],
            ],
        ];
        $result = $this->validator->validate($doc);
        self::assertTrue($result['valid'], json_encode($result['errors']));
    }

    public function testAcceptsSonicSoundAndSfx(): void
    {
        $doc = $this->minimalDoc();
        $doc['views'][0]['root']['nodes'] = [
            [
                'tagName' => 'sonic-sound',
                'attributes' => [
                    'control' => 'game.sound',
                    'out-data-provider' => 'sfx',
                    'bank' => '{"sfx":{"coin":"coin"},"songs":{"theme":{"bpm":120,"instruments":{"kick":"kick"},"patterns":{"A":{"kick":"x . . ."}}}}}',
                ],
            ],
            [
                'tagName' => 'sonic-sfx',
                'attributes' => ['sound' => 'click', 'hover' => 'hover'],
                'nodes' => [['tagName' => 'sonic-button']],
            ],
        ];
        $result = $this->validator->validate($doc);
        self::assertTrue($result['valid'], json_encode($result['errors']));
    }

    public function testRejectsInvalidOrOversizedSoundBank(): void
    {
        $doc = $this->minimalDoc();
        $doc['views'][0]['root']['nodes'] = [['tagName' => 'sonic-sound', 'attributes' => ['bank' => '{"sfx":{"coin":']]];
        $result = $this->validator->validate($doc);
        self::assertFalse($result['valid']);
        self::assertStringContainsString('JSON invalide', $result['errors'][0]['message']);

        $big = json_encode(['sfx' => ['x' => ['wave' => 'sine', 'arp' => array_fill(0, 40000, 7)]]]);
        $doc['views'][0]['root']['nodes'] = [['tagName' => 'sonic-sound', 'attributes' => ['bank' => $big]]];
        $result = $this->validator->validate($doc);
        self::assertFalse($result['valid']);
        self::assertStringContainsString('bank trop volumineuse', $result['errors'][0]['message']);
    }

    public function testCatalogPayloadExplainsSound(): void
    {
        $payload = $this->validator->mcpCatalogPayload(true);
        self::assertArrayHasKey('son', $payload['rules']);
        self::assertStringContainsString('play:{nom: compteur', $payload['rules']['son']);
    }

    public function testCompactCatalogPayloadIsSmall(): void
    {
        $payload = $this->validator->mcpCatalogPayload(true);
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        self::assertNotFalse($encoded);
        self::assertLessThan(20_000, strlen($encoded));
        self::assertArrayHasKey('commonProps', $payload['catalog']);
    }

    public function testAcceptsIntakeSourceAndSink(): void
    {
        $doc = $this->minimalDoc();
        $doc['data'] = [
            'sources' => ['scores' => ['collection' => 'scores', 'publicRead' => false, 'refresh' => 15, 'intake' => [
                'fields' => [
                    'name' => ['type' => 'string', 'max' => 20, 'required' => true],
                    'score' => ['type' => 'integer', 'min' => 0, 'max' => 9999, 'required' => true],
                ],
                'maxRecords' => 40,
            ]]],
            'sinks' => ['scores' => ['collection' => 'scores', 'from' => 'game.outbox', 'merge' => ['name' => 'eleve.name'], 'ack' => 'game']],
        ];
        $result = $this->validator->validate($doc);
        self::assertTrue($result['valid'], json_encode($result['errors']));
    }

    public function testRejectsSinkWithoutIntakeAndBadIntake(): void
    {
        $doc = $this->minimalDoc();
        $doc['data'] = [
            'sources' => [
                'votes' => ['collection' => 'votes'],
                'open' => ['collection' => 'open', 'writeMode' => 'intake', 'intake' => ['fields' => ['msg' => ['type' => 'string']]]],
            ],
            'sinks' => ['s' => ['collection' => 'votes', 'from' => 'nope']],
        ];
        $result = $this->validator->validate($doc);
        self::assertFalse($result['valid']);
        $paths = array_column($result['errors'], 'path');
        self::assertContains('/data/sources/open/writeMode', $paths);
        self::assertContains('/data/sources/open/intake', $paths);
        self::assertContains('/data/sinks/s/collection', $paths);
        self::assertContains('/data/sinks/s/from', $paths);
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

    public function testAcceptsGoogleFonts(): void
    {
        $doc = ['schema' => 'artifacts/1', 'title' => 'F', 'defaultView' => 'home',
            'fonts' => ['Patrick Hand', 'Fredoka:wght@400;700', 'Lora:ital,wght@0,400;1,400'],
            'views' => [['id' => 'home', 'title' => 'H', 'root' => ['nodes' => [['tagName' => 'div']]]]]];
        self::assertTrue($this->validator->validate($doc)['valid']);
    }

    public function testRejectsBadFonts(): void
    {
        $doc = ['schema' => 'artifacts/1', 'title' => 'F', 'defaultView' => 'home',
            'fonts' => ['https://evil.example/x.css', 'Roboto&family=X', 'a', 'B', 'C'],
            'views' => [['id' => 'home', 'title' => 'H', 'root' => ['nodes' => [['tagName' => 'div']]]]]];
        $r = $this->validator->validate($doc);
        self::assertFalse($r['valid']);
        self::assertCount(4, $r['errors']); // trop de polices + URL + injection + minuscule
    }

}
