<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use ApiPlatform\Metadata\McpTool;
use ApiPlatform\State\ProcessorInterface;
use App\Mcp\Processor\ArtifactMcpProcessor;
use App\Mcp\Tool\PublishArtifactTool;
use App\Mcp\Tool\UpdateArtifactTool;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * `publish_preview` (atelier Artefacts) : enregistre le dernier aperçu valide
 * (`preview_artifact`) sans que le modèle ait à réécrire tout le document.
 *
 * - artefact en cours d'édition (contexte de l'atelier) ou déjà publié dans cette
 *   conversation : nouvelle version (update_artifact) ;
 * - sinon : nouvel artefact (publish_artifact).
 * Passe par le processor MCP : mêmes droits, validation, quota et audit que /mcp.
 */
final class PublishPreviewTool implements AgentTool
{
    public const EVENT = 'artifact-published';

    private readonly DraftStore $drafts;

    public function __construct(
        #[Autowire(service: ArtifactMcpProcessor::class)]
        private readonly ProcessorInterface $processor,
        ?DraftStore $drafts = null,
    ) {
        $this->drafts = $drafts ?? new DraftStore();
    }

    public function name(): string
    {
        return 'publish_preview';
    }

    public function description(): string
    {
        return 'Enregistre le dernier aperçu valide (preview_artifact) : nouvel artefact, ou nouvelle version de '
            .'l’artefact en cours d’édition / déjà publié dans cette conversation. Ne renvoie pas le document. '
            .'Uniquement après la confirmation de l’utilisateur. Renvoie {id, slug, url, version}.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Titre (requis pour un nouvel artefact)'],
                'visibility' => ['type' => 'string', 'enum' => ['private', 'link', 'public'], 'description' => 'Nouvel artefact : private par défaut'],
                'description' => ['type' => 'string'],
                'note' => ['type' => 'string', 'description' => 'Note de version (mise à jour)'],
            ],
        ];
    }

    public function execute(array $input, ToolContext $context): ToolResult
    {
        // Brouillon déjà validé (aperçu) : pas le brouillon chargé depuis l'artefact, inchangé.
        $draft = \is_string($context->workspace['draft'] ?? null) ? $this->drafts->current($context) : null;
        if ($draft === null) {
            return ToolResult::json(['error' => 'Aucun aperçu valide dans cette conversation : appelle d’abord preview_artifact.'], true);
        }
        $document = DraftStore::assoc($draft);
        $raw = (object) ['document' => $draft];
        $string = static fn (string $key): ?string => \is_string($input[$key] ?? null) && trim($input[$key]) !== '' ? trim($input[$key]) : null;

        $slug = $context->appContext['artifactSlug'] ?? ($context->workspace['publishedSlug'] ?? null);
        if (\is_string($slug) && $slug !== '') {
            $dto = new UpdateArtifactTool(slug: $slug, document: $document, note: $string('note') ?? 'Atelier');
        } else {
            $title = $string('title') ?? (\is_string($document['title'] ?? null) ? $document['title'] : null);
            if ($title === null) {
                return ToolResult::json(['error' => 'title requis pour un nouvel artefact.'], true);
            }
            $dto = new PublishArtifactTool(
                title: $title,
                document: $document,
                visibility: $string('visibility') ?? 'private',
                description: $string('description'),
            );
        }

        try {
            $result = $this->processor->process($dto, $this->metadata($dto), [], ['raw_arguments' => $raw]);
        } catch (\Throwable $e) {
            return ToolResult::json(['error' => $e->getMessage()], true);
        }
        $payload = self::payload($result);
        if (\is_string($payload['slug'] ?? null)) {
            $context->workspace['publishedSlug'] = $payload['slug'];
            $context->sink->emit(['type' => 'CUSTOM', 'name' => self::EVENT, 'value' => [
                'slug' => $payload['slug'],
                'url' => $payload['url'] ?? null,
                'version' => $payload['version'] ?? null,
            ]]);
        }

        return ToolResult::json($payload);
    }

    private function metadata(object $dto): McpTool
    {
        return (new \ReflectionClass($dto))->getAttributes(McpTool::class)[0]->newInstance();
    }

    /** @return array<string, mixed> */
    private static function payload(mixed $result): array
    {
        if (!$result instanceof CallToolResult) {
            return \is_array($result) ? $result : [];
        }
        foreach ($result->content as $content) {
            if ($content instanceof TextContent) {
                $decoded = json_decode((string) $content->text, true);
                if (\is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return [];
    }
}
