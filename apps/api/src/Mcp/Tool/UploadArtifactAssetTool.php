<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'upload_artifact_asset',
    description: <<<'DESC'
Ajoute (ou remplace) un asset binaire propre à un artefact : image, son, vidéo, modèle 3D, JSON volumineux. Jusqu'à 20 Mo par fichier, 200 par artefact, dans le quota de stockage du propriétaire. L'artefact doit exister (`slug` ou `id`).

`name` : a-z, 0-9, . _ - (64 max) ; l'extension fixe le type et doit correspondre au contenu (png, jpg, jpeg, webp, gif, avif, svg, ogg, mp3, wav, flac, webm, mp4, glb, woff2, json). Le type reçu n'est jamais cru : la signature des octets est vérifiée (un SVG avec script est refusé).

Source du contenu, une seule des deux :
- `fetch` {url, sha256?} : le serveur télécharge le fichier depuis une URL https d'un hôte autorisé (raw.githubusercontent.com, gist.githubusercontent.com). À préférer pour tout fichier de plus de quelques Ko : recopier du base64 à la main altère les octets. `sha256` = empreinte attendue (recommandé).
- `contentBase64` : octets encodés en base64, pour un petit fichier.

Référencer ensuite l'asset dans le document par la valeur exacte `@asset:<name>` partout où une URL est attendue (ex. `src` d'un sprite) : le serveur la remplace par l'URL publique à la lecture. Retourne `{name, contentType, sizeBytes, sha256, ref}`.

Exemple : {"slug":"mon-jeu","name":"heros.png","fetch":{"url":"https://raw.githubusercontent.com/org/repo/main/heros.png","sha256":"…"}}
DESC,
    processor: ArtifactMcpProcessor::class,
)]
final class UploadArtifactAssetTool
{
    /** @param array<string, mixed>|null $fetch {url, sha256?} */
    public function __construct(
        public ?string $id = null,
        public ?string $slug = null,
        public string $name = '',
        public ?string $contentBase64 = null,
        public ?array $fetch = null,
    ) {
    }
}
