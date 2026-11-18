<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ArtifactRun;
use App\Service\ArtifactIo;
use App\Service\ArtifactStream;
use PHPUnit\Framework\TestCase;

final class ArtifactStreamTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function document(array $stream, array $modes = ['interactive', 'embed']): array
    {
        return [
            'schema' => 'artifacts/1',
            'title' => 'Scène',
            'defaultView' => 'home',
            'views' => [['id' => 'home', 'title' => 'Accueil', 'root' => ['nodes' => []]]],
            'data' => ['stores' => ['scene' => ['initial' => ['hue' => 0], 'reducer' => '$state']]],
            'io' => [
                'modes' => $modes,
                'output' => ['schema' => ['type' => 'number'], 'from' => 'scene.hue'],
                'stream' => $stream,
            ],
        ];
    }

    /** @return list<array{path: string, message: string}> */
    private static function check(array $stream, array $modes = ['interactive', 'embed']): array
    {
        $doc = self::document($stream, $modes);

        return ArtifactIo::validateDeclaration($doc['io'], $doc);
    }

    private static function obj(string $json): mixed
    {
        return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private static function full(): array
    {
        return [
            'store' => 'scene',
            'actions' => ['palette' => ['schema' => ['type' => 'object', 'properties' => ['hue' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1]], 'required' => ['hue']]], 'pulse' => []],
            'state' => true,
            'document' => true,
            'score' => true,
            'rate' => 30,
            'progress' => ['from' => 'scene.hue', 'every' => 500],
        ];
    }

    public function testValidDeclaration(): void
    {
        self::assertSame([], self::check(self::full()));
        self::assertSame([], self::check(['store' => 'scene', 'actions' => ['pulse']]));
    }

    public function testStreamNeedsAViewerMode(): void
    {
        $doc = self::document(['store' => 'scene', 'actions' => ['pulse']], ['headless']);
        $doc['io']['input'] = ['schema' => ['type' => 'object'], 'store' => 'scene'];
        $errors = ArtifactIo::validateDeclaration($doc['io'], $doc);
        self::assertSame('/io/stream', $errors[0]['path'] ?? null);
    }

    public function testDeclarationErrors(): void
    {
        self::assertSame('/io/stream/store', self::check(['store' => 'nope', 'actions' => ['a']])[0]['path']);
        self::assertSame('/io/stream/store', self::check(['actions' => ['a']])[0]['path'], 'sans input, store requis');
        self::assertSame('/io/stream', self::check(['store' => 'scene'])[0]['path'], 'rien d’accepté');
        self::assertSame('/io/stream/rate', self::check(['store' => 'scene', 'actions' => ['a'], 'rate' => 500])[0]['path']);
        self::assertSame('/io/stream/progress/from', self::check(['store' => 'scene', 'actions' => ['a'], 'progress' => ['from' => 'ailleurs.x']])[0]['path']);
        self::assertStringStartsWith('/io/stream/actions/a/schema', self::check(['store' => 'scene', 'actions' => ['a' => ['schema' => ['$ref' => '#']]]])[0]['path']);
        self::assertSame('/io/stream/oops', self::check(['store' => 'scene', 'actions' => ['a'], 'oops' => 1])[0]['path']);
    }

    public function testDescribeAndStore(): void
    {
        $doc = self::document(self::full());
        $contract = ArtifactIo::describe($doc);
        self::assertSame(30, $contract['stream']['rate']);
        self::assertTrue($contract['stream']['document']);
        self::assertArrayHasKey('palette', $contract['stream']['actions']);
        self::assertSame('scene', ArtifactStream::store($doc['io']));
    }

    public function testActionMessages(): void
    {
        [$msg, $errors] = ArtifactStream::normalizeIn(self::full(), self::obj('{"type":"action","name":"palette","payload":{"hue":0.5}}'));
        self::assertSame([], $errors);
        self::assertSame('palette', $msg->name);

        // AG-UI CUSTOM {name, value}
        [$msg] = ArtifactStream::normalizeIn(self::full(), self::obj('{"type":"CUSTOM","name":"pulse","value":{}}'));
        self::assertSame('action', $msg->type);
        self::assertInstanceOf(\stdClass::class, $msg->payload);

        [$msg, $errors] = ArtifactStream::normalizeIn(self::full(), self::obj('{"type":"action","name":"palette","payload":{"hue":3}}'));
        self::assertNull($msg);
        self::assertStringContainsString('payload', $errors[0]);

        [$msg, $errors] = ArtifactStream::normalizeIn(self::full(), self::obj('{"type":"action","name":"inconnue"}'));
        self::assertNull($msg);
        self::assertStringContainsString('non déclarée', $errors[0]);
    }

    public function testStateAndDocumentMessages(): void
    {
        [$msg] = ArtifactStream::normalizeIn(self::full(), self::obj('{"type":"STATE_DELTA","delta":[{"op":"replace","path":"/hue","value":0.2}]}'));
        self::assertSame('state.delta', $msg->type);
        self::assertCount(1, $msg->patch);

        [$msg] = ArtifactStream::normalizeIn(self::full(), self::obj('{"type":"STATE_SNAPSHOT","snapshot":{"hue":1}}'));
        self::assertSame('state.snapshot', $msg->type);

        [$msg, $errors] = ArtifactStream::normalizeIn(['store' => 'scene', 'actions' => ['a']], self::obj('{"type":"state.delta","patch":[{"op":"replace","path":"/hue","value":1}]}'));
        self::assertNull($msg);
        self::assertStringContainsString('io.stream.state', $errors[0]);

        [$msg] = ArtifactStream::normalizeIn(self::full(), self::obj('{"type":"document.patch","patch":[{"op":"replace","path":"/title","value":"Nouveau"}]}'));
        self::assertSame('document.patch', $msg->type);

        foreach (['/io/output', '/io', '/files/a', '/schema', ''] as $path) {
            [$msg, $errors] = ArtifactStream::normalizeIn(self::full(), self::obj('{"type":"document.patch","patch":[{"op":"replace","path":"'.$path.'","value":1}]}'));
            self::assertNull($msg, $path);
        }
        [$msg] = ArtifactStream::normalizeIn(self::full(), self::obj('{"type":"document.patch","patch":[{"op":"copy","from":"/io","path":"/data/x"}]}'));
        self::assertNull($msg, 'from verrouillé aussi');
        [$msg] = ArtifactStream::normalizeIn(self::full(), self::obj('{"type":"document.patch","patch":[]}'));
        self::assertNull($msg);
    }

    public function testScoreMessages(): void
    {
        $ok = '{"type":"score","score":{"duration":8000,"loop":true,"events":[{"at":0,"action":"pulse"}],"tracks":[{"path":"/hue","keys":[{"at":0,"value":0},{"at":4000,"value":1,"ease":"inOut"}]}]}}';
        [$msg, $errors] = ArtifactStream::normalizeIn(self::full(), self::obj($ok));
        self::assertSame([], $errors);
        self::assertSame('score', $msg->type);

        $bad = [
            '{"type":"score","score":{"events":[{"at":0,"action":"inconnue"}]}}' => 'non déclarée',
            '{"type":"score","score":{"tracks":[{"path":"hue","keys":[{"at":0,"value":1}]}]}}' => 'pointeur',
            '{"type":"score","score":{"tracks":[{"path":"/h","keys":[{"at":10,"value":1},{"at":5,"value":2}]}]}}' => 'croissants',
            '{"type":"score","score":{"tracks":[{"path":"/h","keys":[{"at":0,"value":1,"ease":"bounce"}]}]}}' => 'ease',
            '{"type":"score","score":{"speed":0}}' => 'speed',
            '{"type":"score","score":{"tempo":120}}' => 'non autorisée',
        ];
        foreach ($bad as $json => $needle) {
            [$msg, $errors] = ArtifactStream::normalizeIn(self::full(), self::obj($json));
            self::assertNull($msg, $json);
            self::assertStringContainsString($needle, implode(' ', $errors), $json);
        }

        [$msg] = ArtifactStream::normalizeIn(['store' => 'scene', 'actions' => ['a']], self::obj('{"type":"score.stop"}'));
        self::assertNull($msg, 'partitions non déclarées');
    }

    public function testUnknownTypeAndSize(): void
    {
        [$msg, $errors] = ArtifactStream::normalizeIn(self::full(), self::obj('{"type":"TEXT_MESSAGE_CONTENT"}'));
        self::assertNull($msg);
        self::assertStringContainsString('inconnu', $errors[0]);

        $big = (object) ['type' => 'state.snapshot', 'state' => (object) ['x' => str_repeat('a', ArtifactStream::MAX_MESSAGE_BYTES)]];
        [$msg, $errors] = ArtifactStream::normalizeIn(self::full(), $big);
        self::assertNull($msg);
        self::assertStringContainsString('volumineux', $errors[0]);

        [$msg] = ArtifactStream::normalizeIn(self::full(), self::obj('{"type":"end"}'));
        self::assertSame('end', $msg->type);
    }

    public function testOutMessages(): void
    {
        [$msg] = ArtifactStream::normalizeOut(self::obj('{"type":"progress","value":{"pct":40}}'));
        self::assertSame('progress', $msg->type);
        [$msg] = ArtifactStream::normalizeOut(self::obj('{"type":"snapshot","seq":12,"state":{"hue":1}}'));
        self::assertSame(12, $msg->seq);
        [$msg] = ArtifactStream::normalizeOut(self::obj('{"type":"snapshot","state":{}}'));
        self::assertNull($msg);
        [$msg] = ArtifactStream::normalizeOut(self::obj('{"type":"action","name":"x"}'));
        self::assertNull($msg);
    }

    public function testOpenApiDescribesTheStream(): void
    {
        $contract = ArtifactIo::describe(self::document(self::full()));
        $spec = ArtifactIo::openApi($contract, 'scene', 'https://api.example');
        self::assertArrayHasKey('/api/public/artifacts/scene/runs/{runId}/events', $spec['paths']);
        self::assertArrayHasKey('/api/public/artifacts/scene/runs/{runId}/stream', $spec['paths']);
        self::assertArrayHasKey('artifact.run.progress', $spec['webhooks']);
        self::assertArrayHasKey('emitToken', $spec['components']['securitySchemes']);
        self::assertArrayHasKey('stream', $spec['components']['schemas']['Run']['properties']);
        $kinds = $spec['paths']['/api/public/artifacts/scene/runs/{runId}/events']['post']['requestBody']['content']['application/json']['schema']['properties']['events']['items']['oneOf'];
        $titles = array_column($kinds, 'title');
        self::assertContains('action palette', $titles);
        self::assertContains('document.patch', $titles);
        self::assertContains('end', $titles);
        self::assertNotFalse(json_encode($spec));

        $plain = ArtifactIo::openApi(ArtifactIo::describe(self::document(['store' => 'scene', 'actions' => ['a']])), 'scene', 'https://api.example');
        self::assertNotContains('document.patch', array_column($plain['paths']['/api/public/artifacts/scene/runs/{runId}/events']['post']['requestBody']['content']['application/json']['schema']['properties']['events']['items']['oneOf'], 'title'));
    }

    public function testEmitDeclaration(): void
    {
        // Télécommande : émission seule, sans store ni actions.
        self::assertSame([], self::check(['emit' => ['from' => 'scene.outbox', 'ack' => 'scene']]));
        self::assertSame('/io/stream/emit/from', self::check(['emit' => ['from' => 'ailleurs.file']])[0]['path']);
        self::assertSame('/io/stream/emit/ack', self::check(['emit' => ['from' => 'scene.outbox', 'ack' => 'nope']])[0]['path']);
        self::assertSame('/io/stream/emit', self::check(['emit' => ['from' => 'scene.outbox', 'to' => 'x']])[0]['path']);

        $doc = self::document(['emit' => ['from' => 'scene.outbox']]);
        self::assertTrue(ArtifactStream::emits($doc['io']));
        self::assertFalse(ArtifactStream::accepts($doc['io']));
        self::assertTrue(ArtifactIo::describe($doc)['stream']['emit']);
        self::assertTrue(ArtifactStream::accepts(self::document(self::full())['io']));
    }

    public function testEmitOutMessages(): void
    {
        [$msg] = ArtifactStream::normalizeOut(self::obj('{"type":"emit","id":"p-3","data":{"pad":"feu"}}'));
        self::assertSame('p-3', $msg->id);
        self::assertSame('feu', $msg->data->pad);
        [$msg] = ArtifactStream::normalizeOut(self::obj('{"type":"emit","id":7,"data":null}'));
        self::assertSame(7, $msg->id);
        [$msg, $errors] = ArtifactStream::normalizeOut(self::obj('{"type":"emit","data":1}'));
        self::assertNull($msg);
        self::assertStringContainsString('id', $errors[0]);
        [$msg] = ArtifactStream::normalizeOut(self::obj('{"type":"emit","id":"","data":1}'));
        self::assertNull($msg);
    }

    public function testRunStreamTokens(): void
    {
        $run = (new \ReflectionClass(ArtifactRun::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(ArtifactRun::class, 'streamMeta'))->setValue($run, []);
        self::assertFalse($run->hasStream());
        $tokens = $run->openStream();
        self::assertTrue($run->hasStream());
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $run->getStreamKey());
        self::assertTrue($run->matchesEmitToken($tokens['emitToken']));
        self::assertFalse($run->matchesEmitToken($tokens['watchToken']));
        self::assertTrue($run->matchesWatchToken($tokens['watchToken']));
        self::assertFalse($run->matchesWatchToken(''));
        self::assertFalse($run->isStreamEnded());
        $run->setStreamMeta(['endedAt' => 1]);
        self::assertTrue($run->isStreamEnded());

        $created = new \DateTimeImmutable('2026-01-01 00:00:00');
        $max = $created->add(new \DateInterval('P7D'));
        $run->extendExpiry($created->add(new \DateInterval('PT24H')), $max);
        self::assertSame('2026-01-02', $run->getExpiresAt()?->format('Y-m-d'));
        $run->extendExpiry($created->add(new \DateInterval('P30D')), $max);
        self::assertSame('2026-01-08', $run->getExpiresAt()?->format('Y-m-d'), 'plafonné');
        $run->extendExpiry($created, $max);
        self::assertSame('2026-01-08', $run->getExpiresAt()?->format('Y-m-d'), 'jamais raccourci');
    }
}
