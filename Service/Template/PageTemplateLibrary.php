<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Template;

/**
 * Bibliotheque des templates de landing page livres avec le plugin.
 *
 * Un template = un dossier dans Templates/Page/ contenant manifest.json et
 * template.html. Ajouter un template = deposer un dossier, rien a declarer.
 */
class PageTemplateLibrary
{
    /** @var array<string, PageTemplate>|null */
    private ?array $templates = null;

    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? \dirname(__DIR__, 2).'/Templates/Page';
    }

    /**
     * @return array<string, PageTemplate>
     */
    public function all(): array
    {
        if (null !== $this->templates) {
            return $this->templates;
        }

        $this->templates = [];

        if (!is_dir($this->directory)) {
            return $this->templates;
        }

        foreach ((array) glob($this->directory.'/*', GLOB_ONLYDIR) as $path) {
            $template = $this->load((string) $path);

            if (null !== $template) {
                $this->templates[$template->key] = $template;
            }
        }

        ksort($this->templates);

        return $this->templates;
    }

    public function get(string $key): ?PageTemplate
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * @param array<string, string> $values
     *
     * @return array{html: string, missing: array<int, string>}
     */
    public function render(PageTemplate $template, array $values): array
    {
        $resolved = $template->getDefaults();

        foreach ($values as $key => $value) {
            $resolved[strtoupper((string) $key)] = (string) $value;
        }

        $missing = array_values(array_diff($template->getRequiredKeys(), array_keys(array_filter(
            $resolved,
            static fn (string $value): bool => '' !== trim($value),
        ))));

        $html = PlaceholderRenderer::render(
            $template->html,
            $resolved,
            $template->getPlaceholderKeys(),
            $template->getJsContextKeys(),
            $template->getHtmlBrContextKeys(),
        );

        return ['html' => $html, 'missing' => $missing];
    }

    private function load(string $path): ?PageTemplate
    {
        $manifestFile = $path.'/manifest.json';
        $htmlFile     = $path.'/template.html';

        if (!is_file($manifestFile) || !is_file($htmlFile)) {
            return null;
        }

        $manifest = json_decode((string) file_get_contents($manifestFile), true);

        if (!is_array($manifest)) {
            return null;
        }

        return new PageTemplate(
            (string) ($manifest['key'] ?? basename($path)),
            (string) ($manifest['name'] ?? basename($path)),
            (string) ($manifest['description'] ?? ''),
            (string) ($manifest['goal'] ?? ''),
            array_map('strval', (array) ($manifest['rules'] ?? [])),
            array_values((array) ($manifest['placeholders'] ?? [])),
            (string) file_get_contents($htmlFile),
        );
    }
}
