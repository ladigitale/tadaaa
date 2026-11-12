<?php

declare(strict_types=1);

namespace App\Mcp\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Mcp\Resource\ArtifactRunnerUiResource;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Result\ReadResourceResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Contenu de `ui://tadaaa/artifact-runner` : page HTML (gabarit `src/Mcp/Ui/artifact-runner.html`)
 * qui charge embed.js du viewer Artefacts, et CSP déclarée pour l'hôte (`_meta.ui.csp`).
 *
 * @implements ProcessorInterface<ArtifactRunnerUiResource, ReadResourceResult>
 */
final class ArtifactRunnerUiProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire('%env(ARTIFACTS_PUBLIC_URL)%')]
        private readonly string $artifactsPublicUrl,
        #[Autowire('%env(ARTIFACT_ASSETS_BASE_URL)%')]
        private readonly string $assetsBaseUrl,
        #[Autowire('%kernel.project_dir%/src/Mcp/Ui/artifact-runner.html')]
        private readonly string $templatePath,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ReadResourceResult
    {
        $request = $context['request'] ?? null;
        $apiOrigin = $request instanceof Request ? $request->getSchemeAndHttpHost() : '';
        $artifactsOrigin = self::origin($this->artifactsPublicUrl);
        $assetsOrigin = self::origin($this->assetsBaseUrl);

        return new ReadResourceResult([
            new TextResourceContents(
                ArtifactRunnerUiResource::URI,
                ArtifactRunnerUiResource::MIME_TYPE,
                self::render((string) file_get_contents($this->templatePath), $artifactsOrigin, $apiOrigin),
                ['ui' => [
                    'prefersBorder' => true,
                    'csp' => self::csp($artifactsOrigin, $apiOrigin, $assetsOrigin),
                    // Demandées seulement : l'artefact n'y accède qu'après un clic, l'hôte peut refuser.
                    'permissions' => ['camera' => new \stdClass(), 'microphone' => new \stdClass(), 'clipboardWrite' => new \stdClass()],
                ]],
            ),
        ]);
    }

    /** Remplace les jetons du gabarit (valeurs encodées en JSON : aucune injection possible). */
    public static function render(string $template, string $artifactsOrigin, string $apiOrigin): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

        return strtr($template, [
            '"__ARTIFACTS_ORIGIN__"' => (string) json_encode($artifactsOrigin, $flags),
            '"__API_ORIGIN__"' => (string) json_encode($apiOrigin, $flags),
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    public static function csp(string $artifactsOrigin, string $apiOrigin, string $assetsOrigin): array
    {
        $own = array_values(array_unique(array_filter([$artifactsOrigin, $apiOrigin, $assetsOrigin])));

        return [
            // Viewer (module, chunks, addons), API publique, assets ; bibliothèques épinglées (jsDelivr).
            'connectDomains' => [...$own, 'https://cdn.jsdelivr.net', 'https://huggingface.co', 'https://cdn-lfs.huggingface.co'],
            'resourceDomains' => [...$own, 'https://cdn.jsdelivr.net', 'https://fonts.googleapis.com', 'https://fonts.gstatic.com'],
        ];
    }

    private static function origin(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
