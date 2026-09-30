<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'update_artifact',
    description: <<<'DESC'
Met à jour un artefact existant (`id` ou `slug`). Fournir `document` pour créer une nouvelle version, et/ou `title`, `visibility`, `description`.

Retourne `{id, slug, url, version}`.

Exemple :
```json
{"slug":"mon-artefact","document":{...},"note":"Correction libellés"}
```
DESC,
    processor: ArtifactMcpProcessor::class,
)]
final class UpdateArtifactTool
{
    /** @param array<string, mixed>|null $document */
    public function __construct(
        public ?string $id = null,
        public ?string $slug = null,
        public ?array $document = null,
        public ?string $title = null,
        public ?string $visibility = null,
        public ?string $description = null,
        public ?string $note = null,
    ) {
    }
}
