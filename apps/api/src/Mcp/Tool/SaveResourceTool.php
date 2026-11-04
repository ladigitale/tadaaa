<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'save_resource',
    description: <<<'DESC'
Enregistre une ressource réutilisable dans la bibliothèque du compte. Si le `slug` existe déjà et que le contenu change, une NOUVELLE VERSION est créée (20 conservées) ; si seul le nom, la description ou les tags changent, aucune version n'est créée.

Champs : `kind` (shader | model3d | data | image | audio), `name` (120 max), `slug` (facultatif : déduit du nom ; a-z 0-9 -), `description`, `tags` (12 max, mots courts), `prompt` = la demande d'origine, À RENSEIGNER pour que l'utilisateur puisse la reprendre et la modifier dans l'atelier. `from` : référence de la ressource de départ (`base:shader/plasma`) pour suivre ses mises à jour.

Contenu, un seul des deux : `content` (texte : shader, JSON) ou `contentBase64` (image, son, .glb) avec `filename` (extension = format). Contrôles : shader = `void mainImage(out vec4 c, in vec2 p)`, UTF-8, 64 Ko, sans `#version` ; données = JSON valide, 1 Mo ; modèle 3D = .glb ou JSON de scène ; image png/jpg/webp/gif/avif/svg ; son ogg/mp3/wav/flac (signature d'octets vérifiée, 20 Mo). Dans le quota de stockage.

Retourne la fiche (`ref`, `version`, `created`, `changed`). Exemple : {"kind":"shader","name":"Plasma lent","tags":["fond"],"prompt":"un plasma très lent","from":"base:shader/plasma","content":"void mainImage(out vec4 o, in vec2 f){ … }"}
DESC,
    processor: ArtifactMcpProcessor::class,
)]
final class SaveResourceTool
{
    /** @param list<string>|null $tags */
    public function __construct(
        public string $kind = '',
        public string $name = '',
        public ?string $slug = null,
        public ?string $description = null,
        public ?array $tags = null,
        public ?string $prompt = null,
        public ?string $from = null,
        public ?string $content = null,
        public ?string $contentBase64 = null,
        public ?string $filename = null,
    ) {
    }
}
