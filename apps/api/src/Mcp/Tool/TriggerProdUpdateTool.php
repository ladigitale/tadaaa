<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Mcp\Processor\OpsMcpProcessor;

#[ApiResource(operations: [])]
#[McpTool(
    name: 'trigger_prod_update',
    description: <<<'DESC'
Super admin uniquement. Déclenche la mise à jour de production (`scripts/update-prod.sh --pull` :
git pull --ff-only, rebuild des SPA et des images, recréation des conteneurs, migrations).

La commande est fixe : aucun argument ne la modifie. `reason` (optionnel) est un motif libre gardé
dans l'historique. Refusé si une mise à jour est déjà en attente ou en cours.

Retourne `{id, status: "queued", …}` tout de suite ; suivre ensuite avec `get_prod_update_status`
(le serveur MCP redémarre pendant la mise à jour : quelques appels peuvent échouer, réessayer).
DESC,
    processor: OpsMcpProcessor::class,
    security: "is_granted('ROLE_ADMIN')",
)]
final class TriggerProdUpdateTool
{
    public function __construct(
        public ?string $reason = null,
    ) {
    }
}
