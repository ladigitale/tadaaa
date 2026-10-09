<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\JsonPatch;
use PHPUnit\Framework\TestCase;

final class JsonPatchTest extends TestCase
{
    private const DOC = '{"a":{"b":[1,2,3],"e":{}},"t":"x","odd/k":1}';

    public function testOperations(): void
    {
        self::assertSame('{"a":{"b":[1,9,2,3,4],"e":{},"n":{}},"t":"x","odd/k":1}', self::run([
            ['op' => 'add', 'path' => '/a/b/1', 'value' => 9],
            ['op' => 'add', 'path' => '/a/b/-', 'value' => 4],
            ['op' => 'add', 'path' => '/a/n', 'value' => new \stdClass()],
        ]));
        self::assertSame('{"a":{"b":[1,3],"e":{}},"t":"y"}', self::run([
            ['op' => 'remove', 'path' => '/a/b/1'],
            ['op' => 'replace', 'path' => '/t', 'value' => 'y'],
            ['op' => 'remove', 'path' => '/odd~1k'],
        ]));
        self::assertSame('{"a":{"b":[1,2,3]},"t":"x","odd/k":1,"e":{}}', self::run([['op' => 'move', 'from' => '/a/e', 'path' => '/e']]));
        self::assertSame('{"a":{"b":[1,2,3],"e":{}},"t":"x","odd/k":1,"c":[1,2,3]}', self::run([
            ['op' => 'test', 'path' => '/t', 'value' => 'x'],
            ['op' => 'copy', 'from' => '/a/b', 'path' => '/c'],
        ]));
    }

    public function testFailuresLeaveTheDocumentUntouched(): void
    {
        $doc = json_decode(self::DOC, false);
        foreach ([
            [['op' => 'replace', 'path' => '/nope', 'value' => 1]],
            [['op' => 'remove', 'path' => '/a/b/7']],
            [['op' => 'add', 'path' => '/a/b/9', 'value' => 1]],
            [['op' => 'add', 'path' => '/x/y', 'value' => 1]],
            [['op' => 'test', 'path' => '/t', 'value' => 'z']],
            [['op' => 'move', 'from' => '/a', 'path' => '/a/z']],
            [['op' => 'replace', 'path' => '/t', 'value' => 'ok'], ['op' => 'frobnicate', 'path' => '/t']],
            [['op' => 'replace', 'path' => 't', 'value' => 1]],
            [['op' => 'add', 'path' => '/t']],
        ] as $i => $ops) {
            try {
                JsonPatch::apply($doc, json_decode((string) json_encode($ops), false));
                self::assertTrue(false, "cas $i : exception attendue");
            } catch (\InvalidArgumentException $e) {
                self::assertStringStartsWith('ops[', $e->getMessage());
            }
        }
        self::assertSame(self::DOC, json_encode($doc, JSON_UNESCAPED_SLASHES));
        self::assertSame([1, 2, 3], JsonPatch::get($doc, '/a/b'));
    }

    /** @param list<array<string, mixed>> $ops */
    private static function run(array $ops): string
    {
        $doc = json_decode(self::DOC, false);
        $out = JsonPatch::apply($doc, json_decode((string) json_encode($ops), false));
        self::assertSame(self::DOC, json_encode($doc, JSON_UNESCAPED_SLASHES), 'original modifié');

        return (string) json_encode($out, JSON_UNESCAPED_SLASHES);
    }
}
