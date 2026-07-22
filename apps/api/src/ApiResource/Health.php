<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\OpenApi\Model\Operation;

#[ApiResource(
    shortName: 'Health',
    operations: [
        new Get(
            uriTemplate: '/health',
            openapi: new Operation(
                summary: 'Health check',
                description: 'Returns API readiness (Phase 0 scaffold).',
            ),
            provider: HealthProvider::class,
        ),
    ],
)]
final class Health
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public readonly string $status = 'ok',
        public readonly string $service = 'tada-api',
    ) {
    }
}
