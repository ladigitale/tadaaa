<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ArtifactSaveService;
use PHPUnit\Framework\TestCase;

final class ArtifactSaveServiceTest extends TestCase
{
    public function testSaveKeys(): void
    {
        self::assertTrue(ArtifactSaveService::isValidKey('Abc_def-0123456789xyzAB'));
        self::assertTrue(ArtifactSaveService::isValidKey(str_repeat('a', 64)));
        self::assertFalse(ArtifactSaveService::isValidKey('court'));
        self::assertFalse(ArtifactSaveService::isValidKey(str_repeat('a', 65)));
        self::assertFalse(ArtifactSaveService::isValidKey('avec espace 0123456789abcdef'));
        self::assertFalse(ArtifactSaveService::isValidKey('me'));
    }
}
