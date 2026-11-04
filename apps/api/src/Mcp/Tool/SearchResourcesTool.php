<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'search_resources',
    description: <<<'DESC'
Cherche dans la bibliothèque de ressources réutilisables : celles du compte (`mine:<slug>`) et celles de base livrées avec la plateforme (`base:<addon>/<id>`, ex. `base:shader/plasma`, `base:shader/sobel`, `base:shader/hough-lines`). À APPELER AVANT de générer un shader, un modèle 3D, un jeu de données, une image ou un son : réutiliser ou adapter l'existant plutôt que refaire.

`kind` : shader | model3d | data | image | audio. `query` : mots présents dans le nom, la description ou les tags. `tag` : un tag exact. `includeBase` (défaut true). Retourne des fiches `{ref, source: mine|base, kind, name, description, tags, version, sizeBytes, sha256, updateAvailable?}` ; pour un shader : `channels` (ce que le shader attend sur iChannel0…) et `params` (réglages uParam0…3 : label, min, max, default). `updateAvailable: true` = la copie du compte a une version de base plus récente.

Lire le contenu avec `get_resource`. Exemple : {"kind":"shader","query":"contours caméra"}
DESC,
    processor: ArtifactMcpProcessor::class,
)]
final class SearchResourcesTool
{
    public function __construct(
        public ?string $query = null,
        public ?string $kind = null,
        public ?string $tag = null,
        public bool $includeBase = true,
        public ?int $limit = null,
        public ?int $offset = null,
    ) {
    }
}
