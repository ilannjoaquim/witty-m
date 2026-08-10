<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Template;

use MauticPlugin\WittyBundle\Service\Template\BuiltInTemplateLoader;
use MauticPlugin\WittyBundle\Service\Template\PageTemplateLibrary;
use PHPUnit\Framework\TestCase;

/**
 * Ce template embarque du JavaScript fonctionnel (WEBINAR_CONFIG, compte a
 * rebours) : la moitie de ses emplacements atterrissent a l'interieur d'un
 * <script>, l'autre moitie dans du HTML visible. Le contexte d'echappement
 * n'est donc pas un detail ici, contrairement au template d'email : un
 * echappement HTML applique a une valeur JS afficherait des entites
 * litterales ("&#039;") au lieu d'une apostrophe sur la page reelle.
 *
 * Exerce PageTemplateLibrary::render() (statique, aucun etat) sur les
 * templates encore livres en fichiers via BuiltInTemplateLoader : voir
 * EmailTemplateLibraryTest pour le raisonnement complet.
 */
class PageTemplateLibraryTest extends TestCase
{
    public function testConfirmationWebinarTemplateIsShippedAndComplete(): void
    {
        $template = BuiltInTemplateLoader::loadPage('confirmation-webinar');

        $this->assertNotNull($template, 'Le template confirmation-webinar doit etre livre avec le plugin.');
        $this->assertNotSame('', $template->getHtml());

        preg_match_all('/\{\{([A-Z0-9_]+)\}\}/', $template->getHtml(), $matches);

        $this->assertSame(
            [],
            array_values(array_diff(array_unique($matches[1]), $template->getPlaceholderKeys())),
            'Tout emplacement present dans le HTML doit etre decrit dans le manifeste.',
        );
    }

    public function testHtmlContextValuesAreEscaped(): void
    {
        $template = BuiltInTemplateLoader::loadPage('confirmation-webinar');

        $rendered = PageTemplateLibrary::render($template, ['CONFIRMED_HEADLINE' => '<script>alert(1)</script> & "quoted"']);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $rendered['html']);
        $this->assertStringContainsString('&lt;script&gt;', $rendered['html']);
    }

    public function testJsContextValuesAreEscapedAsJavaScriptStringsNotHtml(): void
    {
        $template = BuiltInTemplateLoader::loadPage('confirmation-webinar');

        // Une apostrophe (legale telle quelle dans une chaine entre guillemets
        // doubles) et un guillemet (qui doit etre echappe) dans un titre reel.
        $rendered = PageTemplateLibrary::render($template, [
            'EVENT_TITLE' => 'Sam\'s "Launch" Night',
            'JOIN_LINK'   => 'https://example.com/join',
        ]);

        // Le guillemet est echappe, l'apostrophe reste telle quelle...
        $this->assertStringContainsString('title: "Sam\'s \\"Launch\\" Night"', $rendered['html']);
        // ...et surtout PAS transforme en entites HTML, qui s'afficheraient
        // litteralement sur la page puisqu'un <script> n'est jamais parse
        // comme du HTML par le navigateur.
        $this->assertStringNotContainsString('&#039;', $rendered['html']);
        $this->assertStringNotContainsString('&quot;', $rendered['html']);
    }

    public function testJsContextValuesCannotBreakOutOfTheStringLiteral(): void
    {
        $template = BuiltInTemplateLoader::loadPage('confirmation-webinar');

        $rendered = PageTemplateLibrary::render($template, [
            'EVENT_TITLE' => 'Evil"; alert(1); //',
        ]);

        // Le guillemet est echappe : la charge utile reste a l'interieur de la
        // chaine, elle ne devient pas une nouvelle instruction JS.
        $this->assertStringContainsString('title: "Evil\\"; alert(1); //"', $rendered['html']);
    }

    public function testDurationMinutesStaysUnquotedNumericLiteral(): void
    {
        $template = BuiltInTemplateLoader::loadPage('confirmation-webinar');

        $rendered = PageTemplateLibrary::render($template, ['DURATION_MINUTES' => '90']);

        $this->assertStringContainsString('durationMinutes: 90,', $rendered['html']);
    }

    public function testHtmlBrContextPreservesLineBreakButBlocksOtherTags(): void
    {
        $template = BuiltInTemplateLoader::loadPage('confirmation-webinar');

        $rendered = PageTemplateLibrary::render($template, [
            'CONFIRMED_HEADLINE' => 'Your seat is<br>secured.<img src=x onerror=alert(1)>',
        ]);

        // Le retour a la ligne annonce par le manifeste doit vraiment en etre un...
        $this->assertStringContainsString('Your seat is<br>secured.', $rendered['html']);
        // ...mais aucune autre balise ne doit survivre l'echappement.
        $this->assertStringNotContainsString('<img', $rendered['html']);
        $this->assertStringContainsString('&lt;img', $rendered['html']);
    }

    public function testDefaultsFillGenericLabels(): void
    {
        $template = BuiltInTemplateLoader::loadPage('confirmation-webinar');

        $rendered = PageTemplateLibrary::render($template, []);

        $this->assertStringContainsString('>Days<', $rendered['html']);
        $this->assertStringContainsString('Add to Google Calendar', $rendered['html']);
    }

    public function testMissingRequiredPlaceholdersAreReported(): void
    {
        $template = BuiltInTemplateLoader::loadPage('confirmation-webinar');

        $rendered = PageTemplateLibrary::render($template, ['EVENT_TITLE' => 'Launch Night']);

        $this->assertContains('JOIN_LINK', $rendered['missing']);
        $this->assertNotContains('DURATION_MINUTES', $rendered['missing'], 'A une valeur par defaut, ne doit pas etre obligatoire.');
    }

    public function testWebinarLandingTemplateIsShippedAndComplete(): void
    {
        $template = BuiltInTemplateLoader::loadPage('webinar-landing');

        $this->assertNotNull($template, 'Le template webinar-landing doit etre livre avec le plugin.');
        $this->assertNotSame('', $template->getHtml());

        preg_match_all('/\{\{([A-Z0-9_]+)\}\}/', $template->getHtml(), $matches);

        $this->assertSame(
            [],
            array_values(array_diff(array_unique($matches[1]), $template->getPlaceholderKeys())),
            'Tout emplacement present dans le HTML doit etre decrit dans le manifeste.',
        );
    }

    public function testWebinarLandingFormTokenSubstitutesCleanly(): void
    {
        $template = BuiltInTemplateLoader::loadPage('webinar-landing');

        $rendered = PageTemplateLibrary::render($template, ['MAUTIC_FORM_ID' => '42']);

        $this->assertStringContainsString('{form=42}', $rendered['html']);
    }

    public function testCodeModeSentinelIsDocumentedAsTheOnlySafeTemplateValue(): void
    {
        // Pas une assertion sur le fichier HTML lui-meme : le garde-fou reel
        // est dans CreatePageFromTemplateTool, qui doit toujours utiliser
        // 'mautic_code_mode' pour ce type de contenu. Verifie ici que le tool
        // le fait bien, par lecture statique du fichier source.
        $source = (string) file_get_contents(__DIR__.'/../../../Service/Tool/Tools/CreatePageFromTemplateTool.php');

        $this->assertStringContainsString("setTemplate('mautic_code_mode')", $source);
        $this->assertStringNotContainsString("setTemplate('blank')", $source);
    }
}
