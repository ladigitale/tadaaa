<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Artifact;
use App\Entity\ArtifactPlace;
use App\Service\ArtifactPlaceService;
use PHPUnit\Framework\TestCase;

final class ArtifactPlaceOnSiteTest extends TestCase
{
    private function place(): ArtifactPlace
    {
        $artifact = (new \ReflectionClass(Artifact::class))->newInstanceWithoutConstructor();

        return new ArtifactPlace($artifact, 'balises', 47.3, 0.6, ['message' => 'salut'], [], 40, 'hash', 'ip');
    }

    public function testNearWithinRadiusPlusCappedAccuracy(): void
    {
        $p = $this->place();
        // ~111 m au nord
        self::assertTrue(ArtifactPlaceService::near($p, ['lat' => 47.301, 'lon' => 0.6, 'accuracy' => 10]));
        // ~222 m : hors rayon avec une bonne précision, dedans avec une précision de 80 m
        self::assertFalse(ArtifactPlaceService::near($p, ['lat' => 47.302, 'lon' => 0.6, 'accuracy' => 10]));
        self::assertTrue(ArtifactPlaceService::near($p, ['lat' => 47.302, 'lon' => 0.6, 'accuracy' => 80]));
        // une précision énorme ne sert pas d'excuse : plafonnée à 100 m (~333 m ici)
        self::assertFalse(ArtifactPlaceService::near($p, ['lat' => 47.303, 'lon' => 0.6, 'accuracy' => 5000]));
    }

    public function testRefusesMissingOrInvalidPosition(): void
    {
        $p = $this->place();
        self::assertFalse(ArtifactPlaceService::near($p, null));
        self::assertFalse(ArtifactPlaceService::near($p, ['lat' => 'x', 'lon' => 0.6]));
        self::assertFalse(ArtifactPlaceService::near($p, ['lat' => 120, 'lon' => 0.6]));
        self::assertFalse(ArtifactPlaceService::near($p, ['lat' => 47.3]));
    }
}
