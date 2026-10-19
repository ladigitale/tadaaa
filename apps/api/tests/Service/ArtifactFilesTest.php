<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ArtifactFiles;
use PHPUnit\Framework\TestCase;

final class ArtifactFilesTest extends TestCase
{
    /** @return array<string, mixed> */
    private function doc(): array
    {
        return [
            'files' => ['jeu.jsonata' => '$state', 'scene.glsl' => 'void mainImage(){}'],
            'data' => ['stores' => ['jeu' => ['initial' => ['a' => 1], 'reducer' => '@file:jeu.jsonata']]],
            'views' => [['root' => ['nodes' => [['tagName' => 'sonic-shader', 'attributes' => ['image' => '@file:scene.glsl']]]]]],
        ];
    }

    public function testValidDocumentHasNoError(): void
    {
        self::assertSame([], ArtifactFiles::check($this->doc()));
    }

    public function testUnknownReferenceIsReportedWithPath(): void
    {
        $d = $this->doc();
        $d['data']['stores']['jeu']['reducer'] = '@file:absent.jsonata';
        $errors = ArtifactFiles::check($d);
        self::assertCount(1, $errors);
        self::assertSame('/data/stores/jeu/reducer', $errors[0]['path']);
    }

    public function testInvalidFilesStructure(): void
    {
        self::assertNotSame([], ArtifactFiles::check(['files' => ['A B' => 'x']]));
        self::assertNotSame([], ArtifactFiles::check(['files' => ['ok.txt' => 12]]));
        self::assertNotSame([], ArtifactFiles::check(['files' => ['ok.txt' => str_repeat('x', ArtifactFiles::MAX_FILE_BYTES + 1)]]));
        self::assertNotSame([], ArtifactFiles::check(['files' => ['a']]));
    }

    public function testExpandReplacesReferencesAndDropsFiles(): void
    {
        $out = ArtifactFiles::expand($this->doc());
        self::assertArrayNotHasKey('files', $out);
        self::assertSame('$state', $out['data']['stores']['jeu']['reducer']);
        self::assertSame('void mainImage(){}', $out['views'][0]['root']['nodes'][0]['attributes']['image']);
    }

    public function testExpandOnObjectsKeepsEmptyObjectsAndDoesNotMutate(): void
    {
        $raw = json_decode((string) json_encode($this->doc() + ['empty' => new \stdClass()]), false);
        $out = ArtifactFiles::expand($raw);
        self::assertInstanceOf(\stdClass::class, $out->empty);
        self::assertSame('$state', $out->data->stores->jeu->reducer);
        self::assertSame('@file:jeu.jsonata', $raw->data->stores->jeu->reducer);
        self::assertObjectNotHasProperty('files', $out);
    }
}
