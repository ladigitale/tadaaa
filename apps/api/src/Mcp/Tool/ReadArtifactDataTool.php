<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\ArtifactMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'read_artifact_data',
    description: 'Lit les records d’une collection (`slug` + `collection`). Respecte le scope `per_user` (uniquement vos records). `sort` (ex. "-score,nom" : champs de data ou createdAt/updatedAt, - = décroissant) et `limit` (≤ 500) trient et bornent côté serveur.',
    processor: ArtifactMcpProcessor::class,
)]
final class ReadArtifactDataTool
{
    public function __construct(
        public string $slug = '',
        public string $collection = '',
        public ?string $sort = null,
        public ?int $limit = null,
    ) {
    }
}
