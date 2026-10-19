<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'promote_artifact',
    description: <<<'DESC'
Met en ligne le brouillon d'un artefact (`id` ou `slug`) enregistré avec `update_artifact` + `draft:true` : la version la plus récente devient la version publique (et ses collections, collecte et lecture publique, sont appliquées). Erreur s'il n'y a pas de brouillon. Retourne `{id, slug, url, version, liveVersion, hasDraft}`.

Exemple :
```json
{"slug":"mon-jeu"}
```
DESC,
    processor: ArtifactMcpProcessor::class,
)]
final class PromoteArtifactTool
{
    public function __construct(
        public ?string $id = null,
        public ?string $slug = null,
    ) {
    }
}
