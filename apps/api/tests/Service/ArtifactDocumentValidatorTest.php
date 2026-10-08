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
                ['name' => 'sonic-patch'],
                ['name' => 'sonic-voice'],
                ['name' => 'sonic-osc'],
                ['name' => 'sonic-env'],
                ['name' => 'sonic-vca'],
                ['name' => 'sonic-sequencer'],
                ['name' => 'sonic-sampler'],
                ['name' => 'sonic-audio-analyser'],
                ['name' => 'sonic-mic'],
                ['name' => 'sonic-camera'],
                ['name' => 'sonic-video'],
                ['name' => 'sonic-media-start'],
                ['name' => 'sonic-audio-input'],
                ['name' => 'sonic-filter'],
                ['name' => 'sonic-audio-recorder'],
                ['name' => 'sonic-media-recorder'],
                ['name' => 'sonic-media-download'],
                ['name' => 'sonic-shader'],
                ['name' => 'sonic-midi'],
                ['name' => 'sonic-screen'],
                ['name' => 'sonic-grain'],
                ['name' => 'sonic-ladder'],
                ['name' => 'sonic-karplus'],
                ['name' => 'sonic-physics'],
                ['name' => 'sonic-body'],
                ['name' => 'sonic-joint'],
                ['name' => 'sonic-controller'],
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

    public function testAcceptsAudioStack(): void
    {
        $doc = $this->minimalDoc();
        $doc['views'][0]['root']['nodes'] = [
            ['tagName' => 'sonic-patch', 'attributes' => ['id' => 'lead', 'preset' => 'synth/lead', 'params' => '{"cutoff":900}', 'events' => 'g.notes', 'trigger' => 'g.tick']],
            ['tagName' => 'sonic-patch', 'attributes' => ['id' => 'warm'], 'nodes' => [
                ['tagName' => 'sonic-voice', 'nodes' => [
                    ['tagName' => 'sonic-osc', 'attributes' => ['name' => 'o', 'wave' => 'sawtooth']],
                    ['tagName' => 'sonic-env', 'attributes' => ['name' => 'e']],
                    ['tagName' => 'sonic-vca', 'attributes' => ['gain' => 'e']],
                ]],
            ]],
            ['tagName' => 'sonic-sequencer', 'attributes' => ['id' => 'seq', 'pattern' => '{"lead":"c4 [e4 g4]","kit":{"kick":"x...x..."}}', 'store' => 'g']],
            ['tagName' => 'sonic-sampler', 'attributes' => ['id' => 'smp', 'samples' => '{"kick":"https://cdn.example.org/kick.wav","voix":{"ref":"rec.last"},"rel":"sons/a.wav"}']],
            ['tagName' => 'sonic-audio-analyser', 'attributes' => ['id' => 'spectre', 'source' => 'master']],
        ];
        $result = $this->validator->validate($doc);
        self::assertTrue($result['valid'], json_encode($result['errors']));
    }

    public function testRejectsUnsafeSamplesAndOversizedPattern(): void
    {
        foreach (['http://insecure.org/a.wav', 'data:audio/wav;base64,AAAA', 'javascript:alert(1)', 'blob:https://x/1'] as $url) {
            $doc = $this->minimalDoc();
            $doc['views'][0]['root']['nodes'] = [['tagName' => 'sonic-sampler', 'attributes' => ['samples' => json_encode(['a' => ['url' => $url]])]]];
            $result = $this->validator->validate($doc);
            self::assertFalse($result['valid'], $url);
        }
        $doc = $this->minimalDoc();
        $doc['views'][0]['root']['nodes'] = [['tagName' => 'sonic-sequencer', 'attributes' => ['pattern' => json_encode(['a' => str_repeat('x', 20000)])]]];
        $result = $this->validator->validate($doc);
        self::assertFalse($result['valid']);
        self::assertStringContainsString('pattern trop volumineux', $result['errors'][0]['message']);
        $doc['views'][0]['root']['nodes'] = [['tagName' => 'sonic-sequencer', 'attributes' => ['pattern' => '{"a":']]];
        self::assertFalse($this->validator->validate($doc)['valid']);
    }

    public function testAcceptsMediaWithCapabilities(): void
    {
        $doc = $this->minimalDoc();
        $doc['capabilities'] = ['camera', 'microphone'];
        $doc['views'][0]['root']['nodes'] = [
            ['tagName' => 'sonic-camera', 'attributes' => ['id' => 'cam', 'hidden-preview' => '', 'control' => 'g.cam', 'snapshot-provider' => 'gallery.channel0']],
            ['tagName' => 'sonic-mic', 'attributes' => ['id' => 'mic']],
            ['tagName' => 'sonic-video', 'attributes' => ['id' => 'clip', 'src' => 'https://cdn.jsdelivr.net/gh/a/b@1/clip.webm', 'audio-out' => 'master', 'control' => 'g.player']],
            ['tagName' => 'sonic-media-start', 'attributes' => ['for' => 'cam mic', 'label' => 'Activer']],
        ];
        $result = $this->validator->validate($doc);
        self::assertTrue($result['valid'], json_encode($result['errors']));
    }

    public function testRejectsMediaWithoutCapabilitiesOrUnsafeVideo(): void
    {
        $doc = $this->minimalDoc();
        $doc['views'][0]['root']['nodes'] = [['tagName' => 'sonic-camera', 'attributes' => ['id' => 'cam']]];
        $result = $this->validator->validate($doc);
        self::assertFalse($result['valid']);
        self::assertStringContainsString('"camera"', $result['errors'][0]['message']);

        $doc['capabilities'] = ['camera', 'gps'];
        $result = $this->validator->validate($doc);
        self::assertFalse($result['valid']);
        self::assertStringContainsString('Capacité inconnue', $result['errors'][0]['message']);

        $doc['capabilities'] = 'camera';
        self::assertFalse($this->validator->validate($doc)['valid']);

        $doc = $this->minimalDoc();
        $doc['views'][0]['root']['nodes'] = [['tagName' => 'sonic-video', 'attributes' => ['src' => 'http://insecure.org/a.webm']]];
        self::assertFalse($this->validator->validate($doc)['valid']);

        $doc = $this->minimalDoc();
        $doc['capabilities'] = ['microphone'];
        $doc['views'][0]['root']['nodes'] = array_fill(0, 3, ['tagName' => 'sonic-mic']);
        $result = $this->validator->validate($doc);
        self::assertFalse($result['valid']);
        self::assertStringContainsString('Trop de sonic-mic', $result['errors'][0]['message']);
    }

    public function testAcceptsRecordingAndExport(): void
    {
        $doc = $this->minimalDoc();
        $doc['capabilities'] = ['microphone'];
        $doc['views'][0]['root']['nodes'] = [
            ['tagName' => 'sonic-mic', 'attributes' => ['id' => 'mic']],
            ['tagName' => 'sonic-patch', 'attributes' => ['id' => 'clean', 'output' => 'none'], 'nodes' => [
                ['tagName' => 'sonic-audio-input', 'attributes' => ['name' => 'voix', 'source' => '#mic']],
                ['tagName' => 'sonic-filter', 'attributes' => ['type' => 'highpass', 'freq-hz' => '110']],
            ]],
            ['tagName' => 'sonic-audio-recorder', 'attributes' => ['id' => 'rec', 'source' => '#clean', 'control' => 'p.rec', 'max-s' => '6']],
            ['tagName' => 'sonic-sampler', 'attributes' => ['id' => 'pads', 'samples' => '{"A":{"ref":"takes.A"}}', 'events' => 'p.notes', 'trigger' => 'p.tick']],
            ['tagName' => 'sonic-media-recorder', 'attributes' => ['id' => 'export', 'video-source' => '#viz', 'audio-source' => 'master', 'control' => 'p.export']],
            ['tagName' => 'sonic-media-download', 'attributes' => ['source' => 'exportState.last', 'filename' => 'creation']],
        ];
        $result = $this->validator->validate($doc);
        self::assertTrue($result['valid'], json_encode($result['errors']));

        $doc['views'][0]['root']['nodes'] = array_fill(0, 3, ['tagName' => 'sonic-media-recorder', 'attributes' => ['video-source' => '#viz']]);
        $result = $this->validator->validate($doc);
        self::assertFalse($result['valid']);
        self::assertStringContainsString('Trop de sonic-media-recorder', $result['errors'][0]['message']);
    }

    public function testMidiAndScreenNeedCapabilities(): void
    {
        $doc = $this->minimalDoc();
        $doc['views'][0]['root']['nodes'] = [
            ['tagName' => 'sonic-midi', 'attributes' => ['id' => 'midi', 'mpe' => '', 'target' => '#voix', 'output' => 'digitone', 'clock-out' => '#seq']],
            ['tagName' => 'sonic-screen', 'attributes' => ['id' => 'screen', 'hidden-preview' => '']],
            ['tagName' => 'sonic-media-start', 'attributes' => ['for' => 'midi screen']],
        ];
        $result = $this->validator->validate($doc);
        self::assertFalse($result['valid']);
        $messages = implode(' | ', array_column($result['errors'], 'message'));
        self::assertStringContainsString('"midi"', $messages);
        self::assertStringContainsString('"screen"', $messages);

        $doc['capabilities'] = ['midi', 'screen'];
        $result = $this->validator->validate($doc);
        self::assertTrue($result['valid'], json_encode($result['errors']));

        $doc['views'][0]['root']['nodes'] = array_fill(0, 2, ['tagName' => 'sonic-screen']);
        $result = $this->validator->validate($doc);
        self::assertFalse($result['valid']);
        self::assertStringContainsString('Trop de sonic-screen', $result['errors'][0]['message']);
    }

    public function testGrainSampleUrls(): void
    {
        $doc = $this->minimalDoc();
        $grain = fn (string $sample) => ['tagName' => 'sonic-patch', 'attributes' => ['id' => 'p'], 'nodes' => [
            ['tagName' => 'sonic-voice', 'nodes' => [['tagName' => 'sonic-grain', 'attributes' => ['sample' => $sample]], ['tagName' => 'sonic-karplus']]],
            ['tagName' => 'sonic-ladder', 'attributes' => ['freq-hz' => '800', 'res' => '0.9']],
        ]];
        foreach (['takes.voix', 'https://cdn.jsdelivr.net/gh/a/b@1/clip.webm', './son.wav'] as $ok) {
            $doc['views'][0]['root']['nodes'] = [$grain($ok)];
            $result = $this->validator->validate($doc);
            self::assertTrue($result['valid'], $ok.' '.json_encode($result['errors']));
        }
        foreach (['http://insecure.org/a.wav', 'data:audio/wav;base64,AAAA', 'javascript:alert(1)'] as $bad) {
            $doc['views'][0]['root']['nodes'] = [$grain($bad)];
            self::assertFalse($this->validator->validate($doc)['valid'], $bad);
        }
    }

    public function testPhysicsAndController(): void
    {
        $doc = $this->minimalDoc();
        $doc['views'][0]['root']['nodes'] = [
            ['tagName' => 'sonic-physics', 'attributes' => ['id' => 'world', 'gravity' => '0 0', 'bounds' => 'box', 'store' => 'jeu', 'bodies' => 'jeu.bricks', 'input' => 'jeu.input'], 'nodes' => [
                ['tagName' => 'sonic-body', 'attributes' => ['name' => 'paddle', 'type' => 'kinematic', 'shape' => 'polygon', 'points' => '-60,7 60,7 60,-1 -60,-1', 'clamp-x' => '62 738']],
                ['tagName' => 'sonic-body', 'attributes' => ['name' => 'ball', 'shape' => 'circle', 'r' => '8', 'bullet' => '']],
                ['tagName' => 'sonic-joint', 'attributes' => ['type' => 'revolute', 'a' => 'ground', 'b' => 'ball', 'at' => '400 200']],
            ]],
            ['tagName' => 'sonic-controller', 'attributes' => ['id' => 'pad', 'store' => 'jeu', 'analog-action' => 'stick', 'keymap' => '{"a":"launch"}']],
        ];
        $result = $this->validator->validate($doc);
        self::assertTrue($result['valid'], json_encode($result['errors']));

        $doc['views'][0]['root']['nodes'] = array_fill(0, 5, ['tagName' => 'sonic-physics']);
        $result = $this->validator->validate($doc);
        self::assertFalse($result['valid']);
        self::assertStringContainsString('Trop de sonic-physics', $result['errors'][0]['message']);

        $doc['views'][0]['root']['nodes'] = [['tagName' => 'sonic-controller', 'attributes' => ['keymap' => '{pas du json']]];
        self::assertFalse($this->validator->validate($doc)['valid']);
    }

    public function testCatalogPayloadExplainsSound(): void
    {
        $payload = $this->validator->mcpCatalogPayload(true);
        self::assertArrayHasKey('son', $payload['rules']);
        self::assertStringContainsString('play:{nom: compteur', $payload['rules']['son']);
        self::assertArrayHasKey('audio', $payload['rules']);
        self::assertStringContainsString('sonic-audio-recorder', $payload['rules']['audio']);
        self::assertStringContainsString('sonic-media-download', $payload['rules']['media']);
        self::assertStringContainsString('sonic-midi', $payload['rules']['audio']);
        self::assertStringContainsString('sonic-grain', $payload['rules']['audio']);
        self::assertStringContainsString('sonic-physics', $payload['rules']['physique']);
        self::assertStringContainsString('sonic-controller', $payload['rules']['physique']);
        self::assertStringContainsString('sonic-screen', $payload['rules']['media']);
        self::assertStringContainsString('sonic-sequencer', $payload['rules']['audio']);
        self::assertArrayHasKey('media', $payload['rules']);
        self::assertStringContainsString('capabilities', $payload['rules']['media']);
    }

    public function testCompactCatalogPayloadIsSmall(): void
    {
        $payload = $this->validator->mcpCatalogPayload(true);
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        self::assertNotFalse($encoded);
        self::assertLessThan(20_000, strlen($encoded));
        self::assertArrayHasKey('commonProps', $payload['catalog']);
    }

    public function testShippedCatalogMatchesTheViewer(): void
    {
        $dir = \dirname(__DIR__, 2).'/config/artifacts';
        $validator = new ArtifactDocumentValidator(
            $dir.'/catalog.json',
            $dir.'/sdui.schema.json',
            new \App\Service\ArtifactScriptsCatalog($dir.'/scripts-catalog.json'),
        );
        self::assertStringStartsWith('5.', $validator->concordeVersion());
        foreach (['sonic-button', 'sonic-alert', 'sonic-radio', 'sonic-switch', 'sonic-store', 'sonic-sound', 'sonic-physics', 'div'] as $tag) {
            self::assertContains($tag, $validator->allowedTags(), $tag);
        }
        self::assertFalse(\in_array('sonic-example', $validator->allowedTags(), true));

        $full = $validator->mcpCatalogPayload(false, ['sonic-alert']);
        $alert = $full['catalog']['components'][0]['props']['status'];
        self::assertSame(['default', 'info', 'success', 'warning', 'error'], $alert['values']);
        self::assertArrayHasKey('default', $alert);

        $compact = $validator->mcpCatalogPayload(true, ['sonic-alert']);
        self::assertSame(['type' => 'string', 'values' => ['default', 'info', 'success', 'warning', 'error']], $compact['catalog']['components'][0]['props']['status']);
        self::assertSame($validator->concordeVersion(), $compact['catalog']['concordeVersion']);
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
    public function testAcceptsA2uiView(): void
    {
        $result = $this->validator->validate($this->a2uiDoc());
        self::assertTrue($result['valid'], json_encode($result['errors']));
    }

    public function testRejectsRootAndA2uiTogether(): void
    {
        $doc = $this->a2uiDoc();
        $doc['views'][0]['root'] = ['nodes' => []];
        $result = $this->validator->validate($doc);
        self::assertFalse($result['valid']);
        self::assertStringContainsString('exclusifs', $result['errors'][0]['message']);
    }

    public function testRejectsUnsupportedA2uiComponent(): void
    {
        $doc = $this->a2uiDoc();
        $doc['views'][0]['a2ui'][1]['updateComponents']['components'][] = ['id' => 's', 'component' => 'Video', 'url' => 'https://example.com/v.mp4'];
        $result = $this->validator->validate($doc);
        self::assertFalse($result['valid']);
        self::assertStringContainsString('non pris en charge : Video', $result['errors'][0]['message']);
    }

    public function testRejectsA2uiFunctionCalls(): void
    {
        $doc = $this->a2uiDoc();
        $doc['views'][0]['a2ui'][1]['updateComponents']['components'][0]['text'] = ['call' => 'formatString', 'args' => []];
        $result = $this->validator->validate($doc);
        self::assertFalse($result['valid']);
        self::assertStringContainsString('call', $result['errors'][0]['message']);
    }

    public function testAcceptsA2uiTemplateLists(): void
    {
        $doc = $this->a2uiDoc();
        $doc['views'][0]['a2ui'][1]['updateComponents']['components'][2]['children'] = ['path' => '/items', 'componentId' => 'item'];
        $doc['views'][0]['a2ui'][1]['updateComponents']['components'][] = ['id' => 'item', 'component' => 'Text', 'text' => ['path' => 'name']];
        $result = $this->validator->validate($doc);
        self::assertTrue($result['valid'], json_encode($result['errors']));

        $doc['views'][0]['a2ui'][1]['updateComponents']['components'][2]['children'] = ['path' => '/items', 'componentId' => 'item', 'x' => 1];
        self::assertFalse($this->validator->validate($doc)['valid']);

        $doc = $this->a2uiDoc();
        $doc['views'][0]['a2ui'][2]['updateDataModel']['path'] = 'relative';
        $result = $this->validator->validate($doc);
        self::assertFalse($result['valid']);
        self::assertStringContainsString('absolu', $result['errors'][0]['message']);
    }

    public function testRejectsA2uiUnsafeUrlAndUnknownSurface(): void
    {
        $doc = $this->a2uiDoc();
        $doc['views'][0]['a2ui'][1]['updateComponents']['components'][] = ['id' => 'img', 'component' => 'Image', 'url' => 'javascript:alert(1)'];
        $doc['views'][0]['a2ui'][] = ['version' => 'v0.9', 'updateDataModel' => ['surfaceId' => 'ghost', 'path' => '/a', 'value' => 1]];
        $result = $this->validator->validate($doc);
        self::assertFalse($result['valid']);
        $messages = implode(' | ', array_column($result['errors'], 'message'));
        self::assertStringContainsString('https uniquement', $messages);
        self::assertStringContainsString('Surface inconnue', $messages);
    }

    public function testRejectsA2uiOtherCatalogAndDottedPath(): void
    {
        $doc = $this->a2uiDoc();
        $doc['views'][0]['a2ui'][0]['createSurface']['catalogId'] = 'https://example.com/catalog.json';
        $doc['views'][0]['a2ui'][2]['updateDataModel']['path'] = '/show/a.b';
        $result = $this->validator->validate($doc);
        self::assertFalse($result['valid']);
        $messages = implode(' | ', array_column($result['errors'], 'message'));
        self::assertStringContainsString('catalogue de base', $messages);
        self::assertStringContainsString('"."', $messages);
    }

    public function testActionStoreMustBeDeclared(): void
    {
        $doc = $this->a2uiDoc();
        $doc['views'][0]['actionStore'] = 'nope';
        $result = $this->validator->validate($doc);
        self::assertFalse($result['valid']);
        self::assertStringContainsString('actionStore', $result['errors'][0]['message']);

        $doc = $this->minimalDoc();
        $doc['views'][0]['actionStore'] = 'booking';
        $result = $this->validator->validate($doc);
        self::assertFalse($result['valid']);
        self::assertStringContainsString('vues a2ui', $result['errors'][0]['message']);
    }

    public function testA2uiBindings(): void
    {
        $doc = $this->a2uiDoc();
        $doc['views'][0]['a2uiBindings'] = ['/booking' => 'booking'];
        $result = $this->validator->validate($doc);
        self::assertTrue($result['valid'], json_encode($result['errors']));

        $doc['views'][0]['a2uiBindings'] = ['/a.b' => 'booking', '/x' => 'nope'];
        $result = $this->validator->validate($doc);
        self::assertFalse($result['valid']);
        self::assertCount(2, $result['errors']);

        $doc = $this->minimalDoc();
        $doc['views'][0]['a2uiBindings'] = ['/x' => 'booking'];
        self::assertFalse($this->validator->validate($doc)['valid']);
    }

    public function testAcceptsA2uiFormComponents(): void
    {
        $doc = $this->a2uiDoc();
        $extra = [
            ['id' => 'cb', 'component' => 'CheckBox', 'label' => 'OK', 'value' => ['path' => '/form/ok']],
            ['id' => 'cp', 'component' => 'ChoicePicker', 'variant' => 'multipleSelection', 'value' => ['path' => '/form/o'], 'options' => [['label' => 'A', 'value' => 'a']]],
            ['id' => 'sl', 'component' => 'Slider', 'min' => 0, 'max' => 10, 'value' => ['path' => '/form/n']],
            ['id' => 'dt', 'component' => 'DateTimeInput', 'enableDate' => true, 'value' => ['path' => '/form/d']],
            ['id' => 'tb', 'component' => 'Tabs', 'tabs' => [['title' => 'Un', 'child' => 'cb'], ['title' => 'Deux', 'child' => 'cp']]],
            ['id' => 'md', 'component' => 'Modal', 'trigger' => 'book', 'content' => 'sl'],
        ];
        array_push($doc['views'][0]['a2ui'][1]['updateComponents']['components'], ...$extra);
        $result = $this->validator->validate($doc);
        self::assertTrue($result['valid'], json_encode($result['errors']));

        $doc['views'][0]['a2ui'][1]['updateComponents']['components'][] = ['id' => 'bad', 'component' => 'Tabs', 'tabs' => [['title' => ['path' => '/t'], 'child' => 'cb']]];
        $doc['views'][0]['a2ui'][1]['updateComponents']['components'][] = ['id' => 'bad2', 'component' => 'ChoicePicker', 'value' => ['path' => '/x'], 'options' => [['label' => 'A']]];
        $result = $this->validator->validate($doc);
        self::assertFalse($result['valid']);
        self::assertCount(2, $result['errors']);
    }

    public function testA2uiMessagesMustBeWellFormed(): void
    {
        $doc = $this->a2uiDoc();
        $doc['views'][0]['a2ui'][] = ['version' => 'v0.8', 'deleteSurface' => ['surfaceId' => 'booking'], 'createSurface' => []];
        $result = $this->validator->validate($doc);
        self::assertFalse($result['valid']);
        $messages = implode(' | ', array_column($result['errors'], 'message'));
        self::assertStringContainsString('v0.9', $messages);
        self::assertStringContainsString('exactement un', $messages);
    }

    private function a2uiDoc(): array
    {
        $doc = $this->minimalDoc();
        $doc['data'] = ['stores' => ['booking' => ['reducer' => '$state', 'initial' => ['count' => 0]]]];
        $doc['views'][0] = [
            'id' => 'home',
            'title' => 'Réserver',
            'actionStore' => 'booking',
            'a2ui' => [
                ['version' => 'v0.9', 'createSurface' => ['surfaceId' => 'booking', 'catalogId' => 'https://a2ui.org/specification/v0_9/catalogs/basic/catalog.json']],
                ['version' => 'v0.9', 'updateComponents' => ['surfaceId' => 'booking', 'components' => [
                    ['id' => 'title', 'component' => 'Text', 'variant' => 'h3', 'text' => ['path' => '/show/title']],
                    ['id' => 'qty', 'component' => 'TextField', 'label' => 'Places', 'value' => ['path' => '/form/qty']],
                    ['id' => 'root', 'component' => 'Column', 'children' => ['title', 'qty', 'book']],
                    ['id' => 'book-label', 'component' => 'Text', 'text' => 'Réserver'],
                    ['id' => 'book', 'component' => 'Button', 'variant' => 'primary', 'child' => 'book-label',
                        'action' => ['event' => ['name' => 'book', 'context' => ['qty' => ['path' => '/form/qty'], 'n' => 1]]]],
                ]]],
                ['version' => 'v0.9', 'updateDataModel' => ['surfaceId' => 'booking', 'path' => '/', 'value' => ['show' => ['title' => 'Concert'], 'form' => ['qty' => 2]]]],
            ],
        ];

        return $doc;
    }

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
