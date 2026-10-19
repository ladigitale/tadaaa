<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'render_artifact',
    description: <<<'DESC'
Rend un artefact dans le vrai viewer (Chromium headless) et renvoie ce que la validation ne voit pas : erreurs console/page (GLSL, JSONata, composants), requêtes en échec, fps, texte affiché, et une capture d'écran (image). Fournir `document` (brouillon) OU `slug`/`id` (artefact enregistré).

Options : `view` (id de vue), `viewport` ({width, height}), `settleMs` (attente avant capture, ≤ 10000), `fps` (true : profilage sur 2 s → `perf` : fps, durée des images p50/p95/max, images perdues, tâches longues, mémoire JS), `steps` : scénario joué avant la capture, parmi {"wait":ms}, {"press":"Enter"}, {"hold":"Space","ms":300}, {"click":"sélecteur CSS","index":0}, {"shot":"nom"}, et des assertions : {"waitText":"Game over","timeout":5000} (attend un texte), {"expectText":"Score"}, {"expectNoText":"undefined"}, {"expect":"sonic-shader","count":1} (le sélecteur existe).

Retourne `{ok, errors[], warnings[], failedRequests[], assertions[], perf?, text, screenshots}` ; `ok` est faux dès qu'une erreur console/de page est levée ou qu'une assertion échoue. Aucun accès réseau externe depuis la page ; durée max 45 s.

Exemple :
```json
{"slug":"mon-jeu","steps":[{"press":"Enter"},{"wait":1500},{"hold":"Space","ms":300}],"fps":true,"settleMs":500}
```
DESC,
    processor: ArtifactMcpProcessor::class,
)]
final class RenderArtifactTool
{
    /**
     * @param array<string, mixed>|null $document
     * @param array<string, mixed>|null $viewport
     * @param list<mixed>|null          $steps
     */
    public function __construct(
        public ?string $id = null,
        public ?string $slug = null,
        public ?array $document = null,
        public ?string $view = null,
        public ?array $viewport = null,
        public ?array $steps = null,
        public ?int $settleMs = null,
        public ?bool $fps = null,
    ) {
    }
}
