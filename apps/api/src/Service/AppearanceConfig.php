<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Shared Concorde appearance for sister apps (Belts, …).
 * Source: config/appearance/themes.json (regenerate via scripts/export-appearance-themes.mjs).
 */
final class AppearanceConfig
{
    /** @var array<string, mixed>|null */
    private ?array $catalog = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/appearance/themes.json')]
        private readonly string $themesPath,
    ) {
    }

    /**
     * @return list<array{id: string, label: string, dark: bool}>
     */
    public function listThemes(): array
    {
        $out = [];
        foreach ($this->catalog()['themes'] as $id => $theme) {
            $out[] = [
                'id' => (string) $id,
                'label' => (string) ($theme['label'] ?? $id),
                'dark' => (bool) ($theme['dark'] ?? false),
            ];
        }

        return $out;
    }

    /**
     * Full payload for one theme (CSS vars + shell + icons).
     *
     * @return array{
     *   themeId: string,
     *   label: string,
     *   dark: bool,
     *   vars: array<string, string>,
     *   fontsCssUrl: ?string,
     *   shell: array{contentMaxWidth: string, menuPosition: string},
     *   icons: array{library: string, path: string, defaultPrefix: string}
     * }|null
     */
    public function resolve(?string $themeId): ?array
    {
        $catalog = $this->catalog();
        $defaultId = (string) ($catalog['defaultThemeId'] ?? 'default');
        $id = $themeId !== null && $themeId !== '' ? $themeId : $defaultId;
        /** @var array<string, mixed>|null $theme */
        $theme = $catalog['themes'][$id] ?? null;
        if ($theme === null) {
            return null;
        }

        /** @var array<string, string> $vars */
        $vars = [];
        foreach (($theme['vars'] ?? []) as $key => $value) {
            if (\is_string($key) && \is_string($value)) {
                $vars[$key] = $value;
            }
        }

        /** @var array{contentMaxWidth?: string, menuPosition?: string} $shell */
        $shell = $catalog['shell'] ?? [];

        /** @var array{library?: string, path?: string, defaultPrefix?: string} $icons */
        $icons = $catalog['icons'] ?? [];

        return [
            'themeId' => $id,
            'label' => (string) ($theme['label'] ?? $id),
            'dark' => (bool) ($theme['dark'] ?? false),
            'vars' => $vars,
            'fontsCssUrl' => isset($catalog['fontsCssUrl']) && \is_string($catalog['fontsCssUrl'])
                ? $catalog['fontsCssUrl']
                : null,
            'shell' => [
                'contentMaxWidth' => (string) ($shell['contentMaxWidth'] ?? '72rem'),
                'menuPosition' => (string) ($shell['menuPosition'] ?? 'start'),
            ],
            'icons' => [
                'library' => (string) ($icons['library'] ?? 'custom'),
                'path' => (string) ($icons['path'] ?? 'https://cdn.jsdelivr.net/npm/iconoir@7.10.1/icons/$prefix/$name.svg'),
                'defaultPrefix' => (string) ($icons['defaultPrefix'] ?? 'regular'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function catalog(): array
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }

        if (!is_file($this->themesPath)) {
            throw new \RuntimeException(sprintf('Appearance catalog missing: %s', $this->themesPath));
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($this->themesPath), true, 512, \JSON_THROW_ON_ERROR);
        $this->catalog = $decoded;

        return $this->catalog;
    }
}
