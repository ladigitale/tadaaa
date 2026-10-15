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
        self::assertSame(['grid', 'onepage', 'quiz', 'shader', 'survey'], $kits->ids());
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
        self::assertLessThan(5000, \strlen($summary));
        foreach (['quiz :', 'grid :', 'Presets : snake', 'correct: integer', 'shader :', 'survey :'] as $needle) {
            self::assertStringContainsString($needle, $summary);
        }
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
