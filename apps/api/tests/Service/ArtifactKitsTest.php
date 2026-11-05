<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ArtifactDocumentValidator;
use App\Service\ArtifactIntakeSchema;
use App\Service\ArtifactKits;
use App\Service\ArtifactScriptsCatalog;
use PHPUnit\Framework\TestCase;

final class ArtifactKitsTest extends TestCase
{
    private const API = __DIR__.'/../..';

    public function testDirectives(): void
    {
        $kits = self::kits([
            'id' => 'demo', 'title' => 'Demo', 'description' => 'd',
            'params' => ['type' => 'object', 'required' => ['title'], 'properties' => [
                'title' => ['type' => 'string'],
                'n' => ['type' => 'integer', 'default' => 3],
                'flag' => ['type' => 'boolean', 'default' => false],
                'mode' => ['type' => 'string', 'enum' => ['a', 'b'], 'default' => 'a'],
                'items' => ['type' => 'array', 'default' => [], 'items' => ['type' => 'object', 'properties' => [
                    'name' => ['type' => 'string'], 'tags' => ['type' => 'array', 'default' => []], 'kind' => ['type' => 'string']]]],
            ]],
            'presets' => ['big' => ['title' => 'Grand', 'n' => 99]],
            'document' => [
                'title' => ['$param' => 'title'],
                'n' => ['$param' => 'n'],
                'missing' => ['$param' => 'nope', 'default' => 'défaut'],
                'json' => ['$json' => ['t' => ['$param' => 'title'], 'empty' => new \stdClass()]],
                'flag' => ['$if' => 'flag', 'then' => 'oui'],
                'notFlag' => ['$if' => '!flag', 'then' => 'non'],
                'isB' => ['$if' => 'mode=b|c', 'then' => 'b', 'else' => 'pas b'],
                'list' => ['avant', ['$if' => 'flag', 'then' => 'jamais'], ['$each' => 'items', 'template' => [
                    'label' => ['$text' => '{{#1}}. {{.name}} ({{title}})'],
                    'kind' => ['$if' => '.kind=x', 'then' => 'X', 'else' => 'autre'],
                    'tags' => [['$each' => '.tags', 'template' => ['$text' => '{{^#}}.{{#}}={{.}}']]],
                    'raw' => ['$item' => ''],
                ]], 'après'],
                'byName' => ['$eachKey' => 'items', 'key' => ['$text' => '{{.name}}'], 'template' => ['$item' => 'kind', 'default' => '?']],
            ],
        ]);

        $r = $kits->build('demo', json_decode('{"title":"T","mode":"b","items":[{"name":"a","tags":["x","y"],"kind":"x"},{"name":"b","kind":"z","extra":{}}]}', false));
        self::assertSame([], $r['errors']);
        self::assertSame(
            '{"title":"T","n":3,"missing":"défaut","json":"{\"t\":\"T\",\"empty\":{}}","notFlag":"non","isB":"b",'
            .'"list":["avant",{"label":"1. a (T)","kind":"X","tags":["0.0=x","0.1=y"],"raw":{"name":"a","tags":["x","y"],"kind":"x"}},'
            .'{"label":"2. b (T)","kind":"autre","tags":[],"raw":{"name":"b","kind":"z","extra":{},"tags":[]}},"après"],'
            .'"byName":{"a":"x","b":"z"}}',
            json_encode($r['document'], JSON_UNESCAPED_UNICODE),
        );

        $preset = $kits->build('demo', json_decode('{"preset":"big","n":5}', false));
        self::assertSame('Grand', $preset['document']->title);
        self::assertSame(5, $preset['document']->n);
    }

    public function testParameterErrors(): void
    {
        $kits = new ArtifactKits(self::API.'/config/artifacts/kits');
        $r = $kits->build('quiz', json_decode('{"questions":[{"question":"Q","answers":["seule"],"correct":"0"}],"timer":500}', false));
        self::assertNull($r['document']);
        foreach (['params.title : requis.', 'params.timer : maximum 120.', 'params.questions[0].answers : 2 éléments minimum.', 'params.questions[0].correct : integer attendu.'] as $expected) {
            self::assertContains($expected, $r['errors'], implode(' | ', $r['errors']));
        }
        self::assertStringStartsWith('Kit inconnu', $kits->build('nope', null)['errors'][0]);
        self::assertStringStartsWith('preset inconnu', $kits->build('grid', json_decode('{"preset":"tetris"}', false))['errors'][0]);
        self::assertStringContainsString('trop volumineux', $kits->build('quiz', (object) ['title' => str_repeat('x', 90_000)])['errors'][0]);
    }

    public function testShippedKitsBuildValidDocuments(): void
    {
        $kits = new ArtifactKits(self::API.'/config/artifacts/kits');
        self::assertSame(['gestemidi', 'grid', 'onepage', 'quiz', 'sentinel', 'shader', 'survey'], $kits->ids());
        $validator = new ArtifactDocumentValidator(
            self::API.'/config/artifacts/catalog.json',
            self::API.'/config/artifacts/sdui.schema.json',
            new ArtifactScriptsCatalog(self::API.'/config/artifacts/scripts-catalog.json'),
        );
        foreach ($kits->ids() as $id) {
            $params = (string) file_get_contents(self::API.'/tests/Fixtures/kits/'.$id.'.json');
            $built = $kits->build($id, json_decode($params, false));
            self::assertSame([], $built['errors'], $id);
            $document = json_decode((string) json_encode($built['document']), true);
            $result = $validator->validate($document);
            self::assertTrue($result['valid'], $id.' : '.json_encode($result['errors'], JSON_UNESCAPED_UNICODE));
            // Le gain : quelques centaines d'octets de paramètres pour un document complet.
            self::assertLessThan(\strlen((string) json_encode($built['document'])) / 5, \strlen($params), $id);
        }

        $summary = $kits->summary();
        self::assertLessThan(6000, \strlen($summary));
        foreach (['quiz :', 'grid :', 'Presets : snake', 'correct: integer', 'shader :', 'survey :', 'sentinel :', 'Presets : presence, bruit, geste', 'gestemidi :', 'Presets : frappe, doigts, tenue'] as $needle) {
            self::assertStringContainsString($needle, $summary);
        }
    }

    public function testSentinelWiresEachSensorToTheTrigger(): void
    {
        $kits = new ArtifactKits(self::API.'/config/artifacts/kits');
        $trigger = static function (array $params) use ($kits): array {
            $built = $kits->build('sentinel', json_decode((string) json_encode($params), false));
            self::assertSame([], $built['errors']);
            $found = [];
            $walk = static function ($node) use (&$walk, &$found): void {
                if (\is_object($node) || \is_array($node)) {
                    $tag = \is_object($node) ? ($node->tagName ?? null) : null;
                    if ($tag === 'sonic-trigger') {
                        $found[] = $node->attributes;
                    }
                    foreach ((array) $node as $child) {
                        $walk($child);
                    }
                }
            };
            $walk($built['document']);
            self::assertCount(1, $found, 'un seul déclencheur');

            return [(array) $found[0], $built['document']];
        };

        [$object] = $trigger(['preset' => 'presence']);
        self::assertSame('present', $object['key']);
        self::assertArrayNotHasKey('gt', $object);
        self::assertArrayNotHasKey('equals', $object);
        self::assertSame('800', (string) $object['hold-ms']);

        [$smile] = $trigger(['title' => 'S', 'sensor' => 'face', 'face' => 'smile', 'threshold' => 0.6]);
        self::assertSame('smile', $smile['key']);
        self::assertSame('0.6', $smile['gt']);

        [$face] = $trigger(['title' => 'S', 'sensor' => 'face']);
        self::assertSame('present', $face['key']);
        self::assertArrayNotHasKey('gt', $face);

        [$hand] = $trigger(['preset' => 'geste', 'side' => 'left', 'gesture' => 'Thumb_Up']);
        self::assertSame('left.gesture', $hand['key']);
        self::assertSame('Thumb_Up', $hand['equals']);

        [$sound, $doc] = $trigger(['preset' => 'bruit', 'db' => -20]);
        self::assertSame('db', $sound['key']);
        self::assertSame('-20', $sound['gt']);
        self::assertSame(['microphone'], $doc->capabilities);
        self::assertObjectNotHasProperty('sources', $doc->data);
        self::assertObjectNotHasProperty('sinks', $doc->data);

        [, $recorded] = $trigger(['preset' => 'presence', 'record' => true]);
        self::assertSame(['camera'], $recorded->capabilities);
        self::assertSame('journal', $recorded->data->sources->journal->collection);
        self::assertSame('watch.outbox', $recorded->data->sinks->journal->from);
        self::assertTrue($recorded->data->sources->journal->intake->permanent);
    }

    public function testGestemidiWiresEachModeToTheInstrument(): void
    {
        $kits = new ArtifactKits(self::API.'/config/artifacts/kits');
        $build = static function (array $params) use ($kits): array {
            $built = $kits->build('gestemidi', json_decode((string) json_encode($params), false));
            self::assertSame([], $built['errors']);
            $tags = [];
            $walk = static function ($node) use (&$walk, &$tags): void {
                if (\is_object($node) || \is_array($node)) {
                    if (\is_object($node) && isset($node->tagName)) {
                        $tags[$node->tagName][] = (array) ($node->attributes ?? new \stdClass());
                    }
                    foreach ((array) $node as $child) {
                        $walk($child);
                    }
                }
            };
            $walk($built['document']);

            return [$tags, $built['document']];
        };

        // frappe : vitesse de la main -> note, vélocité = pic de vitesse, hauteur = position horizontale
        [$tags, $doc] = $build(['preset' => 'frappe']);
        self::assertSame(['camera'], $doc->capabilities);
        self::assertArrayNotHasKey('sonic-midi', $tags);
        self::assertCount(1, $tags['sonic-patch']);
        self::assertSame('synth/pluck', $tags['sonic-patch'][0]['preset']);
        self::assertSame('right.x,right.y', $tags['sonic-motion'][0]['keys']);
        self::assertCount(1, $tags['sonic-trigger']);
        $hit = $tags['sonic-trigger'][0];
        self::assertSame('motion', $hit['source']);
        self::assertSame('1.5', (string) $hit['gt']);
        self::assertSame('note', $hit['emit']);
        self::assertSame('values.0', $hit['note-key']);
        self::assertSame('48,72', $hit['note-range']);
        self::assertSame('peakVel', $hit['vel-key']);
        // le même couple de DataProviders alimente le synthé
        self::assertSame($hit['emit-provider'], $tags['sonic-patch'][0]['events']);
        self::assertSame($hit['emit-trigger'], $tags['sonic-patch'][0]['trigger']);

        // doigts : un déclencheur par nombre de doigts, note fixe tenue
        [$tags] = $build(['preset' => 'doigts', 'notes' => [60, 64, 67], 'side' => 'left']);
        self::assertArrayNotHasKey('sonic-motion', $tags);
        self::assertCount(3, $tags['sonic-trigger']);
        self::assertSame(['1', '2', '3'], array_map(static fn ($t) => (string) $t['equals'], $tags['sonic-trigger']));
        self::assertSame(['60', '64', '67'], array_map(static fn ($t) => (string) $t['note'], $tags['sonic-trigger']));
        self::assertSame('left.fingerCount', $tags['sonic-trigger'][0]['key']);
        self::assertSame('noteOn', $tags['sonic-trigger'][0]['emit']);

        // tenue + MIDI : geste tenu, capacité midi, port de sortie
        [$tags, $doc] = $build(['preset' => 'tenue', 'sound' => 'midi', 'gesture' => 'Victory', 'output' => 'digitone', 'channel' => 3]);
        self::assertSame(['camera', 'midi'], $doc->capabilities);
        self::assertArrayNotHasKey('sonic-patch', $tags);
        self::assertSame('digitone', $tags['sonic-midi'][0]['output']);
        self::assertSame('3', (string) $tags['sonic-midi'][0]['out-channel']);
        self::assertSame('right.gesture', $tags['sonic-trigger'][0]['key']);
        self::assertSame('Victory', $tags['sonic-trigger'][0]['equals']);
        self::assertSame('right.x', $tags['sonic-trigger'][0]['note-key']);
    }

    public function testSurveyRecordsMatchTheIntakeSchema(): void
    {
        $kits = new ArtifactKits(self::API.'/config/artifacts/kits');
        $built = $kits->build('survey', json_decode((string) file_get_contents(self::API.'/tests/Fixtures/kits/survey.json'), false));
        $doc = json_decode((string) json_encode($built['document']), true);
        $intake = $doc['data']['sources']['answers']['intake'];
        self::assertSame([], ArtifactIntakeSchema::validateDeclaration($intake));
        $fields = ArtifactIntakeSchema::normalize($intake)['fields'];
        self::assertSame(['Oui', 'Non'], $fields['vient']['values']);
        self::assertSame('integer', $fields['personnes']['type']);
        self::assertTrue($fields['plat']['required']);

        // Ce que le reducer du kit envoie (vérifié dans le viewer).
        $record = ['vient' => 'Oui', 'personnes' => 1, 'plat' => 'Dessert', 'aide' => 'Ranger, Cuisiner', 'nom' => 'Julien', 'remarque' => ''];
        self::assertSame([], ArtifactIntakeSchema::validateRecord($fields, $record)['errors']);
        self::assertNotSame([], ArtifactIntakeSchema::validateRecord($fields, ['vient' => 'Peut-être', 'plat' => 'Dessert'])['errors']);
    }

    /** @param array<string, mixed> $kit */
    private static function kits(array $kit): ArtifactKits
    {
        $dir = sys_get_temp_dir().'/kits-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/demo.json', json_encode($kit));

        return new ArtifactKits($dir);
    }
}
