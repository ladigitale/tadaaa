<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ResourceContent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class ResourceContentTest extends TestCase
{
    private const SHADER = 'void mainImage(out vec4 o, in vec2 f){ o = vec4(1.0); }';

    public function testShaderNeedsMainImageAndNoVersion(): void
    {
        self::assertSame('text/x-glsl', ResourceContent::check('shader', '', self::SHADER));
        $this->expectBad(fn () => ResourceContent::check('shader', '', 'void foo(){}'));
        $this->expectBad(fn () => ResourceContent::check('shader', '', "#version 300 es\n".self::SHADER));
        $this->expectBad(fn () => ResourceContent::check('shader', '', str_repeat('a', ResourceContent::MAX_SHADER_BYTES).self::SHADER));
    }

    public function testDataAndModel3d(): void
    {
        self::assertSame('application/json', ResourceContent::check('data', '', ' {"a":1}'));
        $this->expectBad(fn () => ResourceContent::check('data', '', '{oops'));
        self::assertSame('model/gltf-binary', ResourceContent::check('model3d', 'm.glb', 'glTFxxxx'));
        self::assertSame('application/json', ResourceContent::check('model3d', '', '{"nodes":[]}'));
        $this->expectBad(fn () => ResourceContent::check('model3d', 'm.exe', 'glTFxxxx'));
    }

    public function testImageAndAudioSignaturesAreChecked(): void
    {
        self::assertSame('image/png', ResourceContent::check('image', 'a.png', "\x89PNG\r\n\x1a\nxx"));
        $this->expectBad(fn () => ResourceContent::check('image', 'a.png', 'pas un png'));
        self::assertSame('audio/wav', ResourceContent::check('audio', 's.wav', 'RIFF0000WAVEfmt '));
        $this->expectBad(fn () => ResourceContent::check('video', 'a', 'x'));
        $this->expectBad(fn () => ResourceContent::check('shader', '', ''));
    }

    public function testSlugAndTags(): void
    {
        self::assertSame('mon-shader-epique', ResourceContent::slugify('Mon Shader Épique!'));
        self::assertSame('', ResourceContent::slugify('!!!'));
        self::assertSame(['a', 'b'], ResourceContent::normalizeTags(['A', 'a', ' b ', 1, str_repeat('x', 40), '']));
        self::assertCount(ResourceContent::MAX_TAGS, ResourceContent::normalizeTags(array_map(static fn (int $i): string => 't'.$i, range(1, 30))));
    }

    private function expectBad(callable $call): void
    {
        try {
            $call();
        } catch (BadRequestHttpException) {
            self::addToAssertionCount(1);

            return;
        }
        self::fail('BadRequestHttpException attendue.');
    }
}
