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

Sites : `tadaaa` (app + API), `artifacts`, `belts`, `glane`. Sans argument, chaque site n'est
reconstruit que si son dépôt a un commit plus récent que celui déployé ; les autres sont sautés.

- `targets` (optionnel) : limite la mise à jour à ces sites, ex. `["glane"]`.
- `force` (optionnel) : reconstruit les sites visés même sans nouveau commit (changement de
  `.env`, image de base à rafraîchir).
- `reason` (optionnel) : motif libre gardé dans l'historique.

Aucun autre argument ne modifie la commande. Refusé si une mise à jour est déjà en attente ou en cours.

Retourne `{id, status: "queued", …}` tout de suite ; suivre ensuite avec `get_prod_update_status`
(le serveur MCP redémarre pendant la mise à jour : quelques appels peuvent échouer, réessayer).
DESC,
    processor: OpsMcpProcessor::class,
    annotations: ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false],
    security: "is_granted('ROLE_ADMIN')",
)]
final class TriggerProdUpdateTool
{
    /** @param list<string>|null $targets */
    public function __construct(
        public ?string $reason = null,
        public ?array $targets = null,
        public ?bool $force = null,
    ) {
    }
}
