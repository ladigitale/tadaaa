<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ArtifactDocumentValidator;
use App\Service\ArtifactIntakeSchema;
use App\Service\ArtifactKits;
use App\Service\ArtifactScriptsCatalog;
use App\Service\BaseResources;
use App\Service\StoreSimulator;
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
        $kits = self::shipped();
        self::assertSame(['appareil', 'clip-exportable', 'dictee', 'direct', 'gestemidi', 'grid', 'inscription', 'onepage', 'quiz', 'regie', 'sentinel', 'shader', 'survey', 'visite-xr'], $kits->ids());
        $validator = self::validator();
        foreach ($kits->ids() as $id) {
            $params = (string) file_get_contents(self::API.'/tests/Fixtures/kits/'.$id.'.json');
            $built = $kits->build($id, json_decode($params, false));
            self::assertSame([], $built['errors'], $id);
            $document = json_decode((string) json_encode($built['document']), true);
            $result = $validator->validate($document);
            self::assertTrue($result['valid'], $id.' : '.json_encode($result['errors'], JSON_UNESCAPED_UNICODE));
            // Le gain : quelques centaines d'octets de paramètres pour un document complet.
            self::assertLessThan(\strlen((string) json_encode($built['document'])) / 5, \strlen($params), $id);
            // Un nœud sans attribut ({} devient [] côté PHP et casse le rendu).
            self::assertSame([], self::emptyAttributes($document), $id);
        }

        $summary = $kits->summary();
        // 2026-11-26 : 8400 → 10800 avec visite-xr, dictee et clip-exportable (~2,2 Ko).
        self::assertLessThan(10_800, \strlen($summary));
        foreach (['quiz :', 'grid :', 'Presets : snake', 'correct: integer', 'shader :', 'survey :', 'sentinel :', 'Presets : presence, bruit, geste', 'gestemidi :', 'Presets : frappe, doigts, tenue', 'direct :', 'regie :', 'Presets : deux-equipes, solo', 'appareil :', 'inscription :', 'visite-xr :', 'modele: lit|bureau|table', 'dictee :', 'action: vider|ligne|annuler', 'clip-exportable :', 'bibliotheque : degrade, feu, formes, plasma, smoke.'] as $needle) {
            self::assertStringContainsString($needle, $summary);
        }
    }

    public function testVisiteXrPlacesFurnitureAndShowsXrButtons(): void
    {
        $kits = self::shipped();
        $built = $kits->build('visite-xr', json_decode((string) file_get_contents(self::API.'/tests/Fixtures/kits/visite-xr.json'), false));
        self::assertSame([], $built['errors']);
        $doc = json_decode((string) json_encode($built['document']), true);
        self::assertSame(['xr'], $doc['capabilities']);
        $monde = $doc['data']['stores']['visite']['initial']['monde'];
        self::assertSame([[0, 0], [4.2, 0], [4.2, 3.4], [0, 3.4]], $monde['espace']['contour']);
        self::assertSame('chevron', $monde['espace']['zones'][0]['revetement']);
        self::assertSame(['type' => 'fenetre', 'bord' => 1, 'at' => 1.6, 'largeur' => 1.4, 'allege' => 0.9], $monde['ouvertures'][0]);
        $place = array_column($monde['place'], null, 'name');
        // contre un mur (sol), accroché au mur, posé sur un meuble, au plafond, au sol de la pièce
        self::assertSame(['model' => 'lit', 'name' => 'lit', 'against' => 'mur2', 'at' => 0.9, 'params' => ['couette' => '#e07a5f']], $place['lit']);
        self::assertSame(['model' => 'etagere', 'name' => 'meuble4', 'on' => 'mur0', 'at' => [1.2, 1.25]], $place['meuble4']);
        self::assertSame(['model' => 'lampe', 'name' => 'meuble3', 'on' => 'bureau', 'at' => [-0.5, 0]], $place['meuble3']);
        self::assertSame(['on' => 'plafond', 'at' => [2.1, 1.7]], array_intersect_key($place['meuble5'], ['on' => 1, 'at' => 1]));
        self::assertSame(['on' => 'plancher', 'at' => [2.3, 1.5], 'rot' => [0, 15, 0]], array_intersect_key($place['meuble6'], ['on' => 1, 'at' => 1, 'rot' => 1]));

        $tags = self::tags($doc);
        self::assertSame('scene', $tags['sonic-xr'][0]['target']);
        self::assertSame('immersion.xr', $tags['sonic-xr'][0]['control']);
        self::assertSame('visite', $tags['sonic-3d'][0]['dataProvider']);
        $conditions = array_map(static fn (array $a): string => $a['key'].'|'.($a['equals'] ?? ($a['truthy'] ?? false ? 'truthy' : '')), $tags['sonic-if']);
        self::assertSame(['modes.vr|truthy', 'modes.ar|truthy', 'status|unsupported', 'status|denied'], $conditions);
        self::assertSame(['{"mode":"vr"}', '{"mode":"ar"}'], array_column($tags['sonic-action'], 'payload'));

        // Paramètres invalides : modèle hors catalogue, mur inexistant, ouverture sans position.
        $r = $kits->build('visite-xr', json_decode('{"title":"P","largeur":40,"meubles":[{"modele":"canape"},{"modele":"lit","mur":4}],"ouvertures":[{"type":"porte","mur":0}]}', false));
        self::assertNull($r['document']);
        foreach (['params.largeur : maximum 20.', 'params.meubles[0].modele : une valeur parmi', 'params.meubles[1].mur : maximum 3.', 'params.ouvertures[0].x : requis.'] as $expected) {
            self::assertNotEmpty(array_filter($r['errors'], static fn (string $e): bool => str_starts_with($e, $expected)), $expected.' | '.implode(' | ', $r['errors']));
        }
    }

    public function testDicteeWiresListeningKeywordsAndReadBack(): void
    {
        $kits = self::shipped();
        $built = $kits->build('dictee', json_decode((string) file_get_contents(self::API.'/tests/Fixtures/kits/dictee.json'), false));
        $doc = json_decode((string) json_encode($built['document']), true);
        self::assertSame(['microphone'], $doc['capabilities']);
        $tags = self::tags($doc);
        $listen = $tags['sonic-listen'][0];
        self::assertSame(['btn-dicter', 'fr-FR', true, 'dictee'], [$listen['for'], $listen['lang'], $listen['continuous'], $listen['target']]);
        self::assertSame('dictee.texte', $tags['sonic-speak'][0]['text-from']);
        self::assertSame(['btn-dicter', 'btn-relire'], array_values(array_filter(array_column($tags['sonic-button'], 'id'))));
        $store = $tags['sonic-store'][0];
        self::assertSame('annuler', json_decode($store['initial'], true)['commandes'][2]['action']);

        // Sans relecture : ni synthèse vocale ni bouton Relire.
        $quiet = $kits->build('dictee', json_decode('{"title":"T","lang":"en-US","relecture":false}', false));
        self::assertSame([], self::validator()->validate(json_decode((string) json_encode($quiet['document']), true))['errors']);
        $tags = self::tags(json_decode((string) json_encode($quiet['document']), true));
        self::assertArrayNotHasKey('sonic-speak', $tags);
        self::assertSame(['btn-dicter'], array_values(array_filter(array_column($tags['sonic-button'], 'id'))));
        self::assertSame('en-US', $tags['sonic-listen'][0]['lang']);

        $r = $kits->build('dictee', json_decode('{"title":"T","commandes":[{"mot":"stop","action":"quitter"},{"action":"vider"}]}', false));
        self::assertNull($r['document']);
        self::assertContains('params.commandes[1].mot : requis.', $r['errors']);
        self::assertStringStartsWith('params.commandes[0].action : une valeur parmi', $r['errors'][0]);

        // Le réducteur : une ligne par phrase, mots-clés retirés du texte (casse et accents ignorés).
        $dir = self::API.'/tools/simulate-store';
        if (!is_dir($dir.'/node_modules/jsonata')) {
            self::markTestSkipped('Dépendances Node du simulateur non installées (npm install dans tools/simulate-store).');
        }
        $said = static fn (string $text): array => ['type' => 'listen:result', 'payload' => ['text' => $text, 'isFinal' => true]];
        $r = (new StoreSimulator($dir))->simulate(['document' => $doc, 'store' => 'dictee', 'actions' => [
            $said('Bonjour à tous'),
            ['type' => 'listen:result', 'payload' => ['text' => 'provisoire', 'isFinal' => false]],
            $said('Premier point'),
            $said('Nouvelle LIGNE deuxième point'),
            $said('Annuler.'),
            $said('le planning'),
            $said('Effacer tout'),
            $said('ensuite, nouvelle ligne la suite'),
        ]]);
        self::assertTrue($r['ok'], json_encode($r, JSON_UNESCAPED_UNICODE));
        self::assertSame(['ensuite', '', 'la suite'], $r['finalState']['lignes']);
        self::assertSame("ensuite\n\nla suite", $r['finalState']['texte']);
        $r = (new StoreSimulator($dir))->simulate(['document' => $doc, 'store' => 'dictee', 'actions' => [
            $said('Bonjour à tous'), $said('Premier point'), $said('nouvelle ligne Deuxième'), $said('annuler'), $said('Troisième'),
        ]]);
        self::assertSame(['Bonjour à tous', 'Premier point', 'Troisième'], $r['finalState']['lignes']);
        $r = (new StoreSimulator($dir))->simulate(['document' => $doc, 'store' => 'dictee', 'actions' => [$said('Un'), ['type' => 'vider']]]);
        self::assertSame(['lignes' => [], 'texte' => ''], array_intersect_key($r['finalState'], ['lignes' => 1, 'texte' => 1]));
    }

    public function testClipExportableFilmsAWrittenOrLibraryShader(): void
    {
        $kits = self::shipped();
        $built = $kits->build('clip-exportable', json_decode((string) file_get_contents(self::API.'/tests/Fixtures/kits/clip-exportable.json'), false));
        $doc = json_decode((string) json_encode($built['document']), true);
        $plasma = (new BaseResources(self::API.'/config/artifacts/library.json'))->get('shader/plasma');
        self::assertSame($plasma['source'], $doc['files']['clip.glsl']);
        self::assertSame(['ref' => 'base:shader/plasma', 'version' => $plasma['version'], 'sha256' => $plasma['sha256']], $doc['resources']['clip.glsl']);
        // Réglages : valeurs par défaut de la bibliothèque.
        self::assertSame(['param0' => 1, 'param1' => 4, 'param2' => 0.6, 'param3' => 0], $doc['data']['stores']['clipreglages']['initial']);
        $tags = self::tags($doc);
        self::assertSame('@file:clip.glsl', $tags['sonic-shader'][0]['image']);
        self::assertSame('1080 / 1080', $tags['sonic-shader'][0]['aspect-ratio']);
        $export = $tags['sonic-video-export'][0];
        self::assertSame(['#clip-image', 'frames', 6, 30, 1080, 1080, 'btn-export'], [$export['source'], $export['mode'], $export['duration'], $export['fps'], $export['width'], $export['height'], $export['for']]);
        self::assertSame('exportState.file', $tags['sonic-media-download'][0]['source']);
        self::assertSame(['data-bind' => '', '::value' => '$progress', 'max' => '1'], array_intersect_key($tags['sonic-progress'][0], ['data-bind' => 1, '::value' => 1, 'max' => 1]));
        self::assertArrayNotHasKey('capabilities', $doc);

        // GLSL écrit : pas de fichier ni de provenance, réglages donnés.
        $glsl = 'void mainImage(out vec4 o, in vec2 f){ o = vec4(f / iResolution.xy, 0.5 + 0.5 * sin(iTime * uParam0), 1.0); }';
        $own = $kits->build('clip-exportable', (object) ['title' => 'Dégradé', 'shader' => $glsl, 'reglages' => [2], 'fps' => 60]);
        $doc = json_decode((string) json_encode($own['document']), true);
        self::assertSame([], self::validator()->validate($doc)['errors']);
        self::assertArrayNotHasKey('files', $doc);
        self::assertArrayNotHasKey('resources', $doc);
        self::assertSame($glsl, self::tags($doc)['sonic-shader'][0]['image']);
        self::assertSame(['param0' => 2, 'param1' => 0, 'param2' => 0, 'param3' => 0], $doc['data']['stores']['clipreglages']['initial']);

        // Refusés : ni shader ni bibliothèque, shader d'effet (il lui faut une image), cadence hors liste.
        self::assertSame(['params : donner shader ou bibliotheque.'], $kits->build('clip-exportable', (object) ['title' => 'T'])['errors']);
        self::assertStringStartsWith('params.bibliotheque : ressource inconnue (degrade, feu, formes, plasma, smoke)', $kits->build('clip-exportable', (object) ['title' => 'T', 'bibliotheque' => 'sobel'])['errors'][0]);
        self::assertStringStartsWith('params.fps : une valeur parmi', $kits->build('clip-exportable', (object) ['title' => 'T', 'bibliotheque' => 'feu', 'fps' => 12])['errors'][0]);
        // Sans bibliothèque de ressources (service absent) : seul le GLSL écrit passe.
        self::assertNotSame([], (new ArtifactKits(self::API.'/config/artifacts/kits'))->build('clip-exportable', (object) ['title' => 'T', 'bibliotheque' => 'feu'])['errors']);
    }

    public function testRegieDeclaresOneCodePerTeam(): void
    {
        $kits = new ArtifactKits(self::API.'/config/artifacts/kits');
        $built = $kits->build('regie', json_decode('{"preset":"deux-equipes","remote":"mon-ecran","guichet":"","teams":[{"role":"a","label":"A","titre":"Équipe A","color":"#112233","max":20},{"role":"b","label":"B","titre":"Équipe B","color":"#445566"},{"role":"c","label":"C","titre":"Équipe C","color":"#778899"}]}', false));
        self::assertSame([], $built['errors']);
        $doc = json_decode((string) json_encode($built['document']), true);
        self::assertSame(['a', 'b', 'c'], array_column($doc['io']['stream']['pair'], 'role'));
        self::assertSame(['mon-ecran'], array_values(array_unique(array_column($doc['io']['stream']['pair'], 'remote'))));
        self::assertSame([20, 8, 8], array_column($doc['io']['stream']['pair'], 'max'));
        self::assertSame(['a' => 0, 'b' => 0, 'c' => 0], $doc['data']['stores']['regie']['initial']['totaux']);
        $json = (string) json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('artifactRun.pairs.c.url', $json);
        self::assertStringContainsString('"data-value":"c"', $json);
        self::assertArrayNotHasKey('desk', $doc['io']['stream'], 'sans guichet : QR sur la régie');
        self::assertStringContainsString('artifactRun.pairs.a.url', $json);

        $built = $kits->build('regie', json_decode('{"preset":"deux-equipes"}', false));
        $doc = json_decode((string) json_encode($built['document']), true);
        self::assertSame(['remote' => 'inscription-ecrans', 'max' => 2, 'ttl' => 3600], $doc['io']['stream']['desk']);
        $json = (string) json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('artifactRun.desk.url', $json);
        self::assertStringNotContainsString('"text-from":"artifactRun.pairs.', $json, 'avec guichet : les QR d’équipe passent sur le guichet');
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

    private static function shipped(): ArtifactKits
    {
        return new ArtifactKits(self::API.'/config/artifacts/kits', new BaseResources(self::API.'/config/artifacts/library.json'));
    }

    private static function validator(): ArtifactDocumentValidator
    {
        return new ArtifactDocumentValidator(
            self::API.'/config/artifacts/catalog.json',
            self::API.'/config/artifacts/sdui.schema.json',
            new ArtifactScriptsCatalog(self::API.'/config/artifacts/scripts-catalog.json'),
        );
    }

    /**
     * Attributs des nœuds SDUI par balise, dans l'ordre du document.
     *
     * @param array<mixed> $doc
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function tags(array $doc): array
    {
        $tags = [];
        $walk = static function (mixed $node) use (&$walk, &$tags): void {
            if (!\is_array($node)) {
                return;
            }
            if (\is_string($node['tagName'] ?? null)) {
                $tags[$node['tagName']][] = \is_array($node['attributes'] ?? null) ? $node['attributes'] : [];
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk($doc['views'] ?? []);

        return $tags;
    }

    /**
     * Chemins des nœuds dont `attributes` est vide.
     *
     * @param array<mixed> $doc
     *
     * @return list<string>
     */
    private static function emptyAttributes(array $doc): array
    {
        $found = [];
        $walk = static function (mixed $node, string $path) use (&$walk, &$found): void {
            if (!\is_array($node)) {
                return;
            }
            if (\is_string($node['tagName'] ?? null) && \array_key_exists('attributes', $node) && $node['attributes'] === []) {
                $found[] = $path;
            }
            foreach ($node as $key => $child) {
                $walk($child, $path.'/'.$key);
            }
        };
        $walk($doc['views'] ?? [], '/views');

        return $found;
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
