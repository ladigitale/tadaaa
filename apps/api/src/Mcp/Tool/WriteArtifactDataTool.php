<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'write_artifact_data',
    description: <<<'DESC'
Écrit dans une collection (`slug`, `collection`, `data`). Sans `recordId` → création ; avec `recordId` → mise à jour.

Droits : `writeMode` de la collection + membership dataset.
DESC,
    processor: ArtifactMcpProcessor::class,
)]
final class WriteArtifactDataTool
{
    /** @param array<string, mixed>|null $data */
    public function __construct(
        public string $slug = '',
        public string $collection = '',
        public ?array $data = null,
        public ?string $recordId = null,
    ) {
    }
}
