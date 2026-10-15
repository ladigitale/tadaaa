<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'find_icons',
    description: <<<'DESC'
Cherche des noms exacts d’icônes pour `sonic-icon` (les noms inventés sont refusés à la validation).

- `query` : un ou plusieurs mots, en anglais ou en français (ex. « trophée », "music", "flèche droite").
- `library` : `iconoir` (défaut, ~1150 icônes) ou `heroicons` (prefix outline | solid).

Retourne `{library, names}` ; utilisation : `<sonic-icon library="iconoir" name="trophy" size="lg">`.
DESC,
    processor: ArtifactMcpProcessor::class,
)]
final class FindIconsTool
{
    public function __construct(
        public string $query = '',
        public string $library = 'iconoir',
    ) {
    }
}
