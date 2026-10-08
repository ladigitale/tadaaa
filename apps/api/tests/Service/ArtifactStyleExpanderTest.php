<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ArtifactStyleExpander;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class ArtifactStyleExpanderTest extends TestCase
{
    private function doc(array $node, array $styles = ['a' => 'color:red', 'b' => 'margin:0;']): array
    {
        return ['schema' => 'artifacts/1', 'styles' => $styles, 'views' => [['id' => 'h', 'root' => ['nodes' => [$node]]]]];
    }

    public function testExpandsInOrderAndOwnStyleWins(): void
    {
        $out = ArtifactStyleExpander::expand($this->doc(['tagName' => 'div', 'sx' => 'a b', 'attributes' => ['style' => 'color:blue;', 'class' => 'x']]));
        $node = $out['views'][0]['root']['nodes'][0];
        self::assertSame('color:red;margin:0;color:blue;', $node['attributes']['style']);
        self::assertSame('x', $node['attributes']['class']);
        self::assertArrayNotHasKey('sx', $node);
        self::assertArrayHasKey('styles', $out);
    }

    public function testNestedNodesAndIdempotence(): void
    {
        $doc = $this->doc(['tagName' => 'div', 'nodes' => [['tagName' => 'span', 'sx' => 'a']]]);
        $once = ArtifactStyleExpander::expand($doc);
        self::assertSame('color:red;', $once['views'][0]['root']['nodes'][0]['nodes'][0]['attributes']['style']);
        self::assertSame($once, ArtifactStyleExpander::expand($once));
    }

    public function testUnknownStyleRejected(): void
    {
        $this->expectException(BadRequestHttpException::class);
        ArtifactStyleExpander::expand($this->doc(['tagName' => 'div', 'sx' => 'zzz']));
    }

    public function testInvalidDictionaryRejected(): void
    {
        $this->expectException(BadRequestHttpException::class);
        ArtifactStyleExpander::expand($this->doc(['tagName' => 'div'], ['bad name' => 'x']));
    }

    public function testNoStylesIsNoop(): void
    {
        $doc = ['schema' => 'artifacts/1', 'views' => [['id' => 'h', 'root' => ['nodes' => [['tagName' => 'div']]]]]];
        self::assertSame($doc, ArtifactStyleExpander::expand($doc));
    }
}
