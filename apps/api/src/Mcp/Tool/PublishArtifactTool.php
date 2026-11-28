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
- `tags` : catégories, noms d'étiquettes de l'espace (ex. `["Jeu"]`, `["Instrument", "Audio"]`, 6 au plus) ; un nom inconnu crée l'étiquette. La première est la catégorie affichée sur les cartes de la vitrine : toujours en donner une (Jeu, Instrument, Outil, Dessin, Musique, Visualisation, Expérience…).
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
    annotations: ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false],
)]
final class PublishArtifactTool
{
    /**
     * @param array<string, mixed>|null $document
     * @param list<string>|string|null  $tags noms ; une chaîne (JSON ou « Jeu, 3D ») est aussi acceptée
     */
    public function __construct(
        public string $title = '',
        public ?array $document = null,
        public ?string $datasetId = null,
        public ?string $slug = null,
        public string $visibility = 'private',
        public ?string $description = null,
        public array|string|null $tags = null,
    ) {
    }
}
