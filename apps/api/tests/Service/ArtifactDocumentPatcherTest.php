<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ArtifactDocumentPatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class ArtifactDocumentPatcherTest extends TestCase
{
    /** @return array<string, mixed> */
    private function doc(): array
    {
        return [
            'title' => 'A',
            'views' => [['id' => 'h', 'root' => ['nodes' => [['tagName' => 'div', 'attributes' => ['style' => 'a:1']], ['tagName' => 'p']]]]],
            'data' => ['t' => ['jsonata' => 'x := 1; y := 2']],
        ];
    }

    public function testReplaceAddRemove(): void
    {
        $r = ArtifactDocumentPatcher::apply($this->doc(), [['op' => 'replace', 'path' => '/title', 'value' => 'B']]);
        self::assertSame('B', $r['title']);

        $r = ArtifactDocumentPatcher::apply($this->doc(), [['op' => 'add', 'path' => '/views/0/root/nodes/-', 'value' => ['tagName' => 'span']]]);
        self::assertSame('span', $r['views'][0]['root']['nodes'][2]['tagName']);

        $r = ArtifactDocumentPatcher::apply($this->doc(), [['op' => 'add', 'path' => '/views/0/root/nodes/0', 'value' => ['tagName' => 'h1']]]);
        self::assertSame(['h1', 'div', 'p'], array_column($r['views'][0]['root']['nodes'], 'tagName'));

        $r = ArtifactDocumentPatcher::apply($this->doc(), [['op' => 'remove', 'path' => '/views/0/root/nodes/1']]);
        self::assertCount(1, $r['views'][0]['root']['nodes']);
    }

    public function testStrReplaceRequiresUniqueMatch(): void
    {
        $r = ArtifactDocumentPatcher::apply($this->doc(), [['op' => 'str_replace', 'path' => '/data/t/jsonata', 'find' => 'y := 2', 'replace' => 'y := 3']]);
        self::assertSame('x := 1; y := 3', $r['data']['t']['jsonata']);

        $this->expectException(BadRequestHttpException::class);
        ArtifactDocumentPatcher::apply($this->doc(), [['op' => 'str_replace', 'path' => '/data/t/jsonata', 'find' => ':=', 'replace' => '=']]);
    }

    public function testStrReplaceAll(): void
    {
        $r = ArtifactDocumentPatcher::apply($this->doc(), [['op' => 'str_replace', 'path' => '/data/t/jsonata', 'find' => ':=', 'replace' => '=', 'all' => true]]);
        self::assertSame('x = 1; y = 2', $r['data']['t']['jsonata']);
    }

    public function testErrorsAreAllOrNothing(): void
    {
        $this->expectException(BadRequestHttpException::class);
        ArtifactDocumentPatcher::apply($this->doc(), [
            ['op' => 'replace', 'path' => '/title', 'value' => 'C'],
            ['op' => 'replace', 'path' => '/nope/x', 'value' => 1],
        ]);
    }

    public function testRejectsEmptyAndUnknownOps(): void
    {
        foreach ([[], [['op' => 'zzz', 'path' => '/title']], [['op' => 'remove', 'path' => '/views/5']]] as $ops) {
            try {
                ArtifactDocumentPatcher::apply($this->doc(), $ops);
                self::fail('exception attendue');
            } catch (BadRequestHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
