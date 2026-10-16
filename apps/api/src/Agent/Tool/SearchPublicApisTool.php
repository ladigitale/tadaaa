<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use App\Service\ArtifactApisCatalog;

/**
 * `search_public_apis` (atelier Artefacts) : interroge le catalogue d'APIs publiques sans clé
 * (config/artifacts/apis-catalog.json). Sans filtre : l'index ; avec `query` ou `category` :
 * les endpoints, leurs paramètres et un exemple d'appel pour fetch_public_api.
 */
final class SearchPublicApisTool implements AgentTool
{
    public function __construct(private readonly ArtifactApisCatalog $catalog)
    {
    }

    public function name(): string
    {
        return 'search_public_apis';
    }

    public function description(): string
    {
        return 'Cherche dans notre catalogue d’APIs publiques (météo, pays, Wikipédia, livres, taux de change, séismes, '
            .'données ouvertes françaises, musées, quiz…). Sans argument : l’index. Avec query (mots-clés) ou category : '
            .'les endpoints, paramètres et un exemple d’appel. Appelle ensuite fetch_public_api. '
            .'Seules les APIs de ce catalogue sont accessibles.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Mots-clés (ex. « météo », « taux de change », « commune »)'],
                'category' => ['type' => 'string', 'description' => 'Catégorie de l’index (meteo, pays, savoir, finance, terre, france, culture, jeux)'],
            ],
        ];
    }

    public function execute(array $input, ToolContext $context): ToolResult
    {
        $query = \is_string($input['query'] ?? null) ? mb_substr($input['query'], 0, 120) : null;
        $category = \is_string($input['category'] ?? null) ? $input['category'] : null;

        return ToolResult::json($this->catalog->search($query, $category));
    }
}
