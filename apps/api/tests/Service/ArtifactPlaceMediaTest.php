<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ArtifactPlaceService;
use PHPUnit\Framework\TestCase;

final class ArtifactPlaceMediaTest extends TestCase
{
    public function testAcceptsOnlyVoiceAndDrawingDataUrls(): void
    {
        self::assertSame('drawing', ArtifactPlaceService::mediaKind('data:image/svg+xml;base64,PHN2Zz4='));
        self::assertSame('drawing', ArtifactPlaceService::mediaKind('data:image/png;base64,iVBOR'));
        self::assertSame('voice', ArtifactPlaceService::mediaKind('data:audio/webm;codecs=opus;base64,GkXf'));
        self::assertNull(ArtifactPlaceService::mediaKind('data:text/html;base64,PHNjcmlwdD4='));
        self::assertNull(ArtifactPlaceService::mediaKind('javascript:alert(1)'));
        self::assertNull(ArtifactPlaceService::mediaKind('https://exemple.org/son.mp3'));
        self::assertNull(ArtifactPlaceService::mediaKind('data:audio/webm;base64,'.str_repeat('A', 400_000)));
    }
}
