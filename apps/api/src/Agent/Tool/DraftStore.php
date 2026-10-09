<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use ApiPlatform\Metadata\McpTool;
use ApiPlatform\State\ProcessorInterface;
use App\Mcp\Processor\ArtifactMcpProcessor;
use App\Mcp\Tool\GetArtifactTool;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Brouillon de l'atelier : le dernier document valide affiché en aperçu, gardé côté
 * serveur (mémoire de la conversation) en JSON, objets vides compris.
 *
 * C'est sur lui que travaillent edit_preview (patch), read_preview (lecture ciblée) et
 * publish_preview : le modèle n'a jamais à recopier le document entier.
 * En modification d'un artefact existant, le brouillon part de sa version publiée.
 */
final class DraftStore
{
    public function __construct(
        #[Autowire(service: ArtifactMcpProcessor::class)]
        private readonly ?ProcessorInterface $processor = null,
    ) {
    }

    /** Brouillon courant (décodé en objets), chargé depuis l'artefact édité au besoin. */
    public function current(ToolContext $context): ?\stdClass
    {
        $json = $context->workspace['draft'] ?? null;
        if (\is_string($json)) {
            $draft = json_decode($json, false);

            return $draft instanceof \stdClass ? $draft : null;
        }
        $slug = $context->appContext['artifactSlug'] ?? null;
        if (!\is_string($slug) || $this->processor === null) {
            return null;
        }
        $dto = new GetArtifactTool(slug: $slug);
        $metadata = (new \ReflectionClass($dto))->getAttributes(McpTool::class)[0]->newInstance();
        try {
            $result = $this->processor->process($dto, $metadata);
        } catch (\Throwable) {
            return null;
        }
        foreach ($result instanceof CallToolResult ? $result->content : [] as $content) {
            if ($content instanceof TextContent) {
                $payload = json_decode((string) $content->text, false);
                if ($payload instanceof \stdClass && ($payload->document ?? null) instanceof \stdClass) {
                    $this->save($context, $payload->document);

                    return $payload->document;
                }
            }
        }

        return null;
    }

    public function save(ToolContext $context, \stdClass $draft): void
    {
        $context->workspace['draft'] = (string) json_encode($draft, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    /** Forme tableaux (validation, DTO) d'un brouillon. */
    public static function assoc(\stdClass $draft): array
    {
        $assoc = json_decode((string) json_encode($draft), true);

        return \is_array($assoc) ? $assoc : [];
    }
}
