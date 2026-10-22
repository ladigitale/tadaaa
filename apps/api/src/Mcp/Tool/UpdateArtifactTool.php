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
Met à jour un artefact existant (`id` ou `slug`). Fournir `document` (version complète) OU une modification partielle du document courant : `ops` (JSON Patch RFC 6902, chemins JSON Pointer, ex. {"op":"replace","path":"/data/transforms/lb","value":"…"}) et/ou `edits` ([{path, old, new, all?}] : remplace une sous-chaîne `old` dans la chaîne située à `path`, idéal pour retoucher un shader ou un réducteur sans renvoyer tout le document ; `old` doit être unique sauf all:true). Tout ou rien, le résultat est validé. `draft:true` enregistre une version de travail SANS la mettre en ligne : le public garde la version précédente, les éditeurs voient le brouillon (get_artifact, render_artifact, simulate_store, viewer avec ?draft=1) ; `promote_artifact` la met ensuite en ligne. Fournir aussi, si besoin, `title`, `visibility`, `description`.

`fetch` ([{name, url, encoding?, sha256?}]) : charge des fichiers (`files`) depuis une URL https d'un hôte autorisé (raw.githubusercontent.com, gist.githubusercontent.com) AVANT les ops/edits, pour ne pas recopier à la main un gros binaire : `name` = nom du fichier (ex. `plateau.b64`), `encoding` = `base64` (octets encodés, ex. un .glb) ou `text` (défaut), `sha256` = empreinte attendue des octets reçus (recommandé). Peut s'utiliser seul.

Retourne `{id, slug, url, version}`.

Exemples :
```json
{"slug":"mon-artefact","document":{...},"note":"Correction libellés"}
{"slug":"mon-artefact","edits":[{"path":"/data/transforms/tick","old":"$v*2","new":"$v*3"}],"note":"Vitesse"}
```
DESC,
    processor: ArtifactMcpProcessor::class,
)]
final class UpdateArtifactTool
{
    /**
     * @param array<string, mixed>|null $document
     * @param list<mixed>|null         $ops
     * @param list<mixed>|null         $edits
     * @param list<mixed>|null         $fetch
     */
    public function __construct(
        public ?string $id = null,
        public ?string $slug = null,
        public ?array $document = null,
        public ?string $title = null,
        public ?string $visibility = null,
        public ?string $description = null,
        public ?string $note = null,
        public ?array $ops = null,
        public ?array $edits = null,
        public ?bool $draft = null,
        public ?array $fetch = null,
    ) {
    }
}
