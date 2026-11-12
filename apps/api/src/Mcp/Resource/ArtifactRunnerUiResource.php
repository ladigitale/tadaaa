<?php

declare(strict_types=1);

namespace App\Mcp\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpResource;
use App\Mcp\Processor\ArtifactRunnerUiProcessor;
use App\Mcp\Provider\ArtifactRunnerUiProvider;

/**
 * Interface MCP Apps (extension `io.modelcontextprotocol/ui`) liée à `run_artifact` : l'hôte
 * (Claude, ChatGPT…) l'affiche dans la conversation, elle y intègre l'artefact du run et renvoie
 * sa sortie au modèle.
 */
#[ApiResource(operations: [])]
#[McpResource(
    uri: self::URI,
    name: 'artifact-runner',
    description: 'Affiche dans la conversation l’artefact exécuté par run_artifact (mode embed : la personne fait l’étape, la sortie revient au modèle).',
    mimeType: self::MIME_TYPE,
    meta: ['ui' => ['prefersBorder' => true]],
    provider: ArtifactRunnerUiProvider::class,
    processor: ArtifactRunnerUiProcessor::class,
)]
final class ArtifactRunnerUiResource
{
    public const URI = 'ui://tadaaa/artifact-runner';
    public const MIME_TYPE = 'text/html;profile=mcp-app';
}
