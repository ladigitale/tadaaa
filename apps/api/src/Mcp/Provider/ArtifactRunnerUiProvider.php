<?php

declare(strict_types=1);

namespace App\Mcp\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Mcp\Resource\ArtifactRunnerUiResource;

/**
 * @implements ProviderInterface<ArtifactRunnerUiResource>
 */
final class ArtifactRunnerUiProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ArtifactRunnerUiResource
    {
        return new ArtifactRunnerUiResource();
    }
}
