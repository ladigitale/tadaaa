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
Met à jour un artefact existant (`id` ou `slug`). Fournir `document` (document complet) OU `patch` (modifications ciblées) pour créer une nouvelle version, et/ou `title`, `visibility`, `description`.

`patch` évite de renvoyer tout le document : liste d’opérations appliquées dans l’ordre sur la version courante (200 max, tout ou rien). Chemins = JSON Pointer (ex. `/views/0/root/nodes/2/attributes/style`) :
- `{"op":"replace","path":"/title","value":"Nouveau"}`
- `{"op":"add","path":"/views/0/root/nodes/-","value":{…}}` (`-` = à la fin, un index insère)
- `{"op":"remove","path":"/views/0/root/nodes/3"}`
- `{"op":"str_replace","path":"/views/0/root/nodes/0/attributes/reducer","find":"ancien","replace":"nouveau"}` : remplace du texte dans une longue chaîne (reducer, shader, banque de sons) ; `find` doit être unique sauf `"all":true`.
Le document obtenu est validé comme un document complet.

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
    /**
     * @param array<string, mixed>|null       $document
     * @param list<array<string, mixed>>|null $patch
     */
    public function __construct(
        public ?string $id = null,
        public ?string $slug = null,
        public ?array $document = null,
        public ?string $title = null,
        public ?string $visibility = null,
        public ?string $description = null,
        public ?string $note = null,
        public ?array $patch = null,
    ) {
    }
}
