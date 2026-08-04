<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Theme;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Un theme casse ne se voit qu'a l'ouverture du builder, et le message d'erreur
 * y est peu parlant. Ces verifications attrapent les trois façons de le casser :
 * un config.json invalide (theme absent du selecteur), une double accolade
 * (interpretee par Twig), un fichier de contenu qui n'a pas la forme attendue
 * par le type de builder declare (MJML pour un email, HTML pour une page).
 *
 * Les assertions specifiques a une fonctionnalite (email, page) sont gardees
 * par les "features" declarees dans config.json : un theme de page n'a pas de
 * tokens de desinscription, un theme d'email n'a pas de formulaire.
 */
class ThemeIntegrityTest extends TestCase
{
    private const THEMES_DIR = __DIR__.'/../../Themes';

    /**
     * @return array<string, array{0: string}>
     */
    public static function themeProvider(): array
    {
        $themes = [];

        foreach ((array) glob(self::THEMES_DIR.'/*', GLOB_ONLYDIR) as $path) {
            $themes[basename((string) $path)] = [(string) $path];
        }

        return $themes;
    }

    #[DataProvider('themeProvider')]
    public function testConfigIsValidForTheThemePicker(string $path): void
    {
        $this->assertFileExists($path.'/config.json', 'ThemeHelper ignore tout dossier sans config.json.');

        $config = $this->readConfig($path);

        $this->assertNotEmpty($config['name'] ?? '', 'Le nom affiche dans le selecteur est obligatoire.');
        $this->assertNotEmpty($config['builder'] ?? [], 'Sans builder declare, Mautic retombe sur le builder legacy.');

        $features = (array) ($config['features'] ?? []);
        $this->assertNotEmpty($features, 'Sans feature declaree, le theme n apparait dans aucun selecteur (email, page, form).');
        $this->assertEmpty(
            array_diff($features, ['email', 'page', 'form']),
            'Feature inconnue de ThemeHelper.',
        );
    }

    #[DataProvider('themeProvider')]
    public function testRequiredTemplatesArePresent(string $path): void
    {
        // Exige par l'import de theme en zip (ThemeHelper::install), quelles
        // que soient les features declarees.
        $this->assertFileExists($path.'/html/message.html.twig');
        $this->assertFileExists($path.'/thumbnail.png', 'Le selecteur affiche une vignette.');

        $features = (array) ($this->readConfig($path)['features'] ?? []);

        if (in_array('email', $features, true)) {
            $this->assertFileExists($path.'/html/email.html.twig');
        }

        if (in_array('page', $features, true)) {
            $this->assertFileExists($path.'/html/page.html.twig');
        }
    }

    #[DataProvider('themeProvider')]
    public function testEmailTemplateIsMjml(string $path): void
    {
        $this->skipUnlessFeature($path, 'email');

        $content = (string) file_get_contents($path.'/html/email.html.twig');

        // GrapesJsController bascule en mode MJML sur la presence de <mjml>.
        $this->assertStringContainsString('<mjml>', $content);
        $this->assertStringContainsString('</mjml>', $content);
    }

    #[DataProvider('themeProvider')]
    public function testPageTemplateIsHtml(string $path): void
    {
        $this->skipUnlessFeature($path, 'page');

        $content = (string) file_get_contents($path.'/html/page.html.twig');

        $this->assertMatchesRegularExpression('/<html[\s>]/i', $content);
        $this->assertStringContainsString('{form=', $content, 'Une landing page sans formulaire Mautic ne collecte rien.');
    }

    #[DataProvider('themeProvider')]
    public function testNoTwigInterpolationInTheThemeSource(string $path): void
    {
        foreach ((array) glob($path.'/html/*.twig') as $twigFile) {
            $content = (string) file_get_contents((string) $twigFile);

            // Le fichier passe par Twig : une double accolade y serait evaluee
            // comme une variable, et un emplacement de texte disparaitrait
            // silencieusement plutot que de lever une erreur.
            $this->assertDoesNotMatchRegularExpression(
                '/\{\{(?!\s*(getAssetUrl|template|content|isNew|basePath))/',
                $content,
                sprintf('%s : utiliser du texte d exemple ou des [crochets], pas de double accolade.', basename((string) $twigFile)),
            );
        }
    }

    #[DataProvider('themeProvider')]
    public function testMauticTokensArePresentInTheFooter(string $path): void
    {
        $this->skipUnlessFeature($path, 'email');

        $content = (string) file_get_contents($path.'/html/email.html.twig');

        foreach (['{unsubscribe_url}', '{webview_url}'] as $token) {
            $this->assertStringContainsString($token, $content, sprintf('%s est obligatoire (RGPD / delivrabilite).', $token));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readConfig(string $path): array
    {
        $config = json_decode((string) file_get_contents($path.'/config.json'), true);

        return is_array($config) ? $config : [];
    }

    private function skipUnlessFeature(string $path, string $feature): void
    {
        $features = (array) ($this->readConfig($path)['features'] ?? []);

        if (!in_array($feature, $features, true)) {
            $this->markTestSkipped(sprintf('%s ne declare pas la feature %s.', basename($path), $feature));
        }
    }
}
