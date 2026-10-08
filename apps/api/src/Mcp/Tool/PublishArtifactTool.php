<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'publish_artifact',
    description: <<<'DESC'
Publie un nouvel artefact SDUI. Valide le document avant enregistrement ; refuse si invalide.

- `datasetId` : optionnel : par défaut le jeu « Artefacts » (créé automatiquement, non supprimable).
- `visibility` : `private` | `link` | `public`
- Retourne `{id, slug, url, version}` — `url` absolue (viewer Artefacts).

Exemple minimal :
```json
{
  "title": "Mon tableau",
  "visibility": "public",
  "document": {
    "schema": "artifacts/1",
    "title": "Mon tableau",
    "defaultView": "home",
    "views": [{"id":"home","title":"Accueil","root":{"nodes":[{"tagName":"div","attributes":{"class":"p-4"}}]}}]
  }
}
```

Une vue peut aussi être décrite en A2UI v0.9 (`"a2ui": [messages]` à la place de `root`, `"actionStore"` pour recevoir les actions) : voir `rules.a2ui` de `get_artifact_catalog`.
DESC,
    processor: ArtifactMcpProcessor::class,
)]
final class PublishArtifactTool
{
    /** @param array<string, mixed>|null $document */
    public function __construct(
        public string $title = '',
        public ?array $document = null,
        public ?string $datasetId = null,
        public ?string $slug = null,
        public string $visibility = 'private',
        public ?string $description = null,
    ) {
    }
}
