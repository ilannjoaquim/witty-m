<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Template;

/**
 * Bibliotheque des templates d'email livres avec le plugin.
 *
 * Un template = un dossier dans Templates/Email/ contenant manifest.json,
 * template.html (compile) et, optionnellement, template.mjml (source).
 * Ajouter un template = deposer un dossier, rien a declarer.
 *
 * Le HTML est pre-compile a la construction du plugin : compiler du MJML
 * demande Node, que PHP n'a pas. Le MJML est conserve pour que l'email reste
 * editable dans le builder MJML de Mautic (plugin GrapesJS).
 */
class EmailTemplateLibrary
{
    /** @var array<string, EmailTemplate>|null */
    private ?array $templates = null;

    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? \dirname(__DIR__, 2).'/Templates/Email';
    }

    /**
     * @return array<string, EmailTemplate>
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

    public function get(string $key): ?EmailTemplate
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Remplace les emplacements par les valeurs fournies.
     *
     * Toutes les valeurs sont echappees : le modele produit du texte, pas du
     * HTML. Sans cela, une esperluette ou un chevron dans un titre casserait la
     * mise en page, et une valeur malveillante pourrait injecter du balisage.
     *
     * @param array<string, string> $values
     *
     * @return array{html: string, mjml: string|null, missing: array<int, string>}
     */
    public function render(EmailTemplate $template, array $values): array
    {
        $resolved = $template->getDefaults();

        foreach ($values as $key => $value) {
            $resolved[strtoupper((string) $key)] = (string) $value;
        }

        $missing = array_values(array_diff($template->getRequiredKeys(), array_keys(array_filter(
            $resolved,
            static fn (string $value): bool => '' !== trim($value),
        ))));

        $keys   = $template->getPlaceholderKeys();
        $brKeys = $template->getHtmlBrContextKeys();

        return [
            'html'    => PlaceholderRenderer::render($template->html, $resolved, $keys, [], $brKeys),
            'mjml'    => null !== $template->mjml ? PlaceholderRenderer::render($template->mjml, $resolved, $keys, [], $brKeys) : null,
            'missing' => $missing,
        ];
    }

    private function load(string $path): ?EmailTemplate
    {
        $manifestFile = $path.'/manifest.json';
        $htmlFile     = $path.'/template.html';
        $mjmlFile     = $path.'/template.mjml';

        if (!is_file($manifestFile) || !is_file($htmlFile)) {
            return null;
        }

        $manifest = json_decode((string) file_get_contents($manifestFile), true);

        if (!is_array($manifest)) {
            return null;
        }

        return new EmailTemplate(
            (string) ($manifest['key'] ?? basename($path)),
            (string) ($manifest['name'] ?? basename($path)),
            (string) ($manifest['description'] ?? ''),
            (string) ($manifest['goal'] ?? ''),
            array_map('strval', (array) ($manifest['rules'] ?? [])),
            array_values((array) ($manifest['placeholders'] ?? [])),
            (string) file_get_contents($htmlFile),
            is_file($mjmlFile) ? (string) file_get_contents($mjmlFile) : null,
        );
    }
}
