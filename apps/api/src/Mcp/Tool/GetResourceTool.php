<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'get_resource',
    description: <<<'DESC'
Lit une ressource de la bibliothèque par `ref` (`mine:<slug>` ou `base:<addon>/<id>`), avec sa fiche et son contenu. `version` : une version précédente d'une ressource du compte (voir `versions: true`).

Contenu : shader, données JSON et scène JSON sont renvoyés en texte (`content`) ; image, son et .glb en base64 (`contentBase64`) seulement jusqu'à 256 Ko, au-delà la fiche seule est renvoyée (utiliser alors `@asset:` après `upload_artifact_asset`, ou l'URL de téléchargement de la page Ressources). Un shader se colle dans l'attribut `image` d'un `sonic-shader` ; relier ses `params` (uParam0…3) à un store avec leurs bornes.

`versions: true` ajoute l'historique `{version, current, sizeBytes, sha256, prompt, createdAt}`. Pour partir d'une ressource de base, l'enregistrer ensuite avec `save_resource` en renseignant `from` (la mise à jour de la base sera alors signalée).

Quand tu copies le contenu dans un artefact (fichier de `files`), note sa provenance dans `resources` du document : {"<fichier>": {"ref", "version", "sha256"}} avec les valeurs de la fiche, pour que l'utilisateur soit prévenu des mises à jour.
DESC,
    processor: ArtifactMcpProcessor::class,
)]
final class GetResourceTool
{
    public function __construct(
        public string $ref = '',
        public ?int $version = null,
        public bool $versions = false,
    ) {
    }
}
