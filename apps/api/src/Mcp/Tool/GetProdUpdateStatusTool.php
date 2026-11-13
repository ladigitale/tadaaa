<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\OpsMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'get_prod_update_status',
    description: <<<'DESC'
Super admin uniquement. État d'une mise à jour de production lancée par `trigger_prod_update`.

- `runId` : id renvoyé au déclenchement ; absent = dernier run, plus `recent` (5 derniers).
- `tail` : nombre de lignes de journal (0–400, défaut 60).

`run.status` : `queued` (pas encore pris par l'hôte) | `running` | `succeeded` | `failed`
| `unhealthy` (script OK mais /api/health ne répond pas) | `interrupted` | `stale`.
Aussi : `fromSha` / `toSha` (commit avant / après), `exitCode`, `health`, `startedAt`,
`finishedAt`, `lastStep` (dernière étape du script) et `log`.
DESC,
    processor: OpsMcpProcessor::class,
    security: "is_granted('ROLE_ADMIN')",
)]
final class GetProdUpdateStatusTool
{
    public function __construct(
        public ?string $runId = null,
        public ?int $tail = null,
    ) {
    }
}
