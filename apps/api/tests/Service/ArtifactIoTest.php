<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ArtifactIo;
use PHPUnit\Framework\TestCase;

final class ArtifactIoTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function document(array $io): array
    {
        return [
            'schema' => 'artifacts/1',
            'title' => 'Somme',
            'defaultView' => 'home',
            'views' => [['id' => 'home', 'title' => 'Accueil', 'root' => ['nodes' => [
                ['tagName' => 'sonic-store', 'attributes' => ['id' => 'vue', 'initial' => '{}']],
            ]]]],
            'data' => [
                'stores' => ['calc' => ['initial' => ['phase' => 'idle'], 'reducer' => '$state']],
                'transforms' => ['resume' => ['jsonata' => '1']],
            ],
            'io' => $io,
        ];
    }

    /** @return array<string, mixed> */
    private static function io(array $override = []): array
    {
        return array_replace([
            'modes' => ['headless'],
            'input' => ['schema' => ['type' => 'object', 'properties' => ['values' => ['type' => 'array', 'items' => ['type' => 'number']]], 'required' => ['values']], 'store' => 'calc'],
            'output' => ['schema' => ['type' => 'number'], 'from' => 'calc.total', 'when' => "phase='done'"],
        ], $override);
    }

    public function testValidDeclaration(): void
    {
        $doc = self::document(self::io());
        self::assertSame([], ArtifactIo::validateDeclaration($doc['io'], $doc));
    }

    public function testUnknownStoreIsRejected(): void
    {
        $io = self::io(['input' => ['schema' => ['type' => 'object'], 'store' => 'kalk']]);
        $doc = self::document($io);
        $paths = array_column(ArtifactIo::validateDeclaration($io, $doc), 'path');
        self::assertContains('/io/input/store', $paths);
    }

    public function testSonicStoreIdCountsAsStore(): void
    {
        $io = self::io(['modes' => ['interactive'], 'input' => ['schema' => ['type' => 'object'], 'store' => 'vue'], 'output' => ['schema' => ['type' => 'object'], 'from' => 'resume']]);
        $doc = self::document($io);
        self::assertSame([], ArtifactIo::validateDeclaration($io, $doc));
    }

    public function testHeadlessOutputMustReadInputStore(): void
    {
        $io = self::io(['output' => ['schema' => ['type' => 'number'], 'from' => 'resume']]);
        $doc = self::document($io);
        $paths = array_column(ArtifactIo::validateDeclaration($io, $doc), 'path');
        self::assertContains('/io/output/from', $paths);
    }

    public function testHeadlessRequiresInput(): void
    {
        $io = self::io();
        unset($io['input']);
        $io['output']['from'] = 'calc';
        $doc = self::document($io);
        $paths = array_column(ArtifactIo::validateDeclaration($io, $doc), 'path');
        self::assertContains('/io/input', $paths);
    }

    public function testUnsupportedSchemaKeywordIsRejected(): void
    {
        $io = self::io(['output' => ['schema' => ['anyOf' => [['type' => 'number']]], 'from' => 'calc.total']]);
        $doc = self::document($io);
        $paths = array_column(ArtifactIo::validateDeclaration($io, $doc), 'path');
        self::assertContains('/io/output/schema/anyOf', $paths);
    }

    public function testInputSchemaMustBeObject(): void
    {
        $io = self::io(['input' => ['schema' => ['type' => 'string'], 'store' => 'calc']]);
        $doc = self::document($io);
        $paths = array_column(ArtifactIo::validateDeclaration($io, $doc), 'path');
        self::assertContains('/io/input/schema/type', $paths);
    }

    public function testValueValidation(): void
    {
        $schema = ['type' => 'object', 'properties' => [
            'values' => ['type' => 'array', 'items' => ['type' => 'number'], 'maxItems' => 3],
            'label' => ['type' => 'string', 'maxLength' => 4, 'pattern' => '^[a-z]+$'],
            'mode' => ['enum' => ['a', 'b']],
        ], 'required' => ['values'], 'additionalProperties' => false];

        self::assertSame([], ArtifactIo::validateValue($schema, ['values' => [1, 2.5], 'label' => 'ok', 'mode' => 'a']));
        self::assertSame([], ArtifactIo::validateValue($schema, json_decode('{"values":[1]}', false)));
        self::assertNotSame([], ArtifactIo::validateValue($schema, ['label' => 'ok']));
        self::assertNotSame([], ArtifactIo::validateValue($schema, ['values' => ['x']]));
        self::assertNotSame([], ArtifactIo::validateValue($schema, ['values' => [1, 2, 3, 4]]));
        self::assertNotSame([], ArtifactIo::validateValue($schema, ['values' => [], 'label' => 'TROPLONG']));
        self::assertNotSame([], ArtifactIo::validateValue($schema, ['values' => [], 'mode' => 'c']));
        self::assertNotSame([], ArtifactIo::validateValue($schema, ['values' => [], 'extra' => 1]));
    }

    public function testIntegerAcceptsWholeFloat(): void
    {
        self::assertSame([], ArtifactIo::validateValue(['type' => 'integer'], 3.0));
        self::assertNotSame([], ArtifactIo::validateValue(['type' => 'integer'], 3.5));
        self::assertSame([], ArtifactIo::validateValue(['type' => 'number'], 3));
    }

    public function testHeadlessPlanAndPluck(): void
    {
        $io = self::io(['headless' => ['actions' => [['type' => 'tick', 'repeat' => 5]], 'maxSteps' => 50]]);
        $plan = ArtifactIo::headlessPlan($io, ['values' => [1]]);
        self::assertSame('calc', $plan['store']);
        self::assertSame('io:input', $plan['actions'][0]['type']);
        self::assertSame('tick', $plan['actions'][1]['type']);
        self::assertSame("phase='done'", $plan['until']);
        self::assertSame(50, $plan['maxSteps']);
        self::assertSame('total', $plan['path']);
        self::assertSame(3, ArtifactIo::pluck(['total' => 3], 'total'));
        self::assertSame(2, ArtifactIo::pluck(json_decode('{"a":{"b":[1,2]}}', false), 'a.b.1'));
        self::assertNull(ArtifactIo::pluck(['a' => 1], 'b'));
    }

    public function testDescribe(): void
    {
        $contract = ArtifactIo::describe(self::document(self::io(['title' => 'Somme de valeurs'])));
        self::assertSame('Somme de valeurs', $contract['title']);
        self::assertSame(['headless'], $contract['modes']);
        self::assertSame('object', $contract['inputSchema']['type']);
        self::assertNull(ArtifactIo::describe(['title' => 'x']));
    }
}
