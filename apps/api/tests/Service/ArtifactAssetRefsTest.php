<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ArtifactAssetRefs;
use PHPUnit\Framework\TestCase;

final class ArtifactAssetRefsTest extends TestCase
{
    private const SHA = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';

    /** @return array<string, mixed> */
    private function doc(): array
    {
        return [
            'views' => [['root' => ['nodes' => [
                ['tagName' => 'sonic-sprite', 'attributes' => ['image' => '@asset:heros.png', 'name' => 'a']],
                ['tagName' => 'sonic-sprite', 'attributes' => ['image' => '@asset:absent.png']],
            ]]]],
            'note' => 'texte @asset:heros.png dans une phrase',
        ];
    }

    public function testValidSyntaxHasNoError(): void
    {
        self::assertSame([], ArtifactAssetRefs::check($this->doc()));
    }

    public function testInvalidNameIsReportedWithPath(): void
    {
        $d = $this->doc();
        $d['views'][0]['root']['nodes'][0]['attributes']['image'] = '@asset:Mauvais Nom.PNG';
        $errors = ArtifactAssetRefs::check($d);
        self::assertCount(1, $errors);
        self::assertSame('/views/0/root/nodes/0/attributes/image', $errors[0]['path']);
    }

    public function testExpandReplacesKnownReferencesOnly(): void
    {
        $out = ArtifactAssetRefs::expand($this->doc(), 'https://api.example.test/', 'mon-jeu', ['heros.png' => self::SHA]);
        $nodes = $out['views'][0]['root']['nodes'];
        self::assertSame('https://api.example.test/api/public/artifacts/mon-jeu/assets/heros.png?v=abcdef012345', $nodes[0]['attributes']['image']);
        self::assertSame('@asset:absent.png', $nodes[1]['attributes']['image']);
        self::assertSame('texte @asset:heros.png dans une phrase', $out['note']); // seule une valeur exacte est une référence
    }

    public function testLinkTokenIsAppended(): void
    {
        $out = ArtifactAssetRefs::expand(['a' => '@asset:x.png'], 'https://h', 'mon-jeu', ['x.png' => self::SHA], 'tok/en');
        self::assertStringEndsWith('?v=abcdef012345&k=tok%2Fen', $out['a']);
    }

    public function testExpandHandlesWireObjectsWithoutMutatingInput(): void
    {
        $wire = json_decode('{"a":{"src":"@asset:x.png"},"empty":{}}', false);
        $out = ArtifactAssetRefs::expand($wire, 'https://h', 'mon-jeu', ['x.png' => self::SHA]);
        self::assertSame('@asset:x.png', $wire->a->src);
        self::assertStringStartsWith('https://h/api/public/artifacts/mon-jeu/assets/x.png', $out->a->src);
        self::assertInstanceOf(\stdClass::class, $out->empty);
    }

    public function testNoAssetsMeansDocumentUntouched(): void
    {
        self::assertSame($this->doc(), ArtifactAssetRefs::expand($this->doc(), 'https://h', 'mon-jeu', []));
    }

    public function testFindAndExpandInRunInput(): void
    {
        // Entrée d'un run : une image et un jeu de gestes en assets, une chaîne ordinaire laissée telle quelle.
        $input = (object) ['image' => '@asset:photo.png', 'gestesFichier' => '@asset:peinture.json', 'consigne' => 'Un phare'];
        self::assertSame([['/image', 'photo.png'], ['/gestesFichier', 'peinture.json']], ArtifactAssetRefs::find($input));
        $out = ArtifactAssetRefs::expand($input, 'https://api.example', 'abysse', ['photo.png' => self::SHA]);
        self::assertSame('https://api.example/api/public/artifacts/abysse/assets/photo.png?v=abcdef012345', $out->image);
        self::assertSame('@asset:peinture.json', $out->gestesFichier);
        self::assertSame('Un phare', $out->consigne);
    }
}
