<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Template;

use MauticPlugin\WittyBundle\Service\Template\EmailTemplateLibrary;
use PHPUnit\Framework\TestCase;

/**
 * La substitution reçoit du texte produit par un modèle et le place dans du
 * HTML envoyé à des contacts : l'échappement et le respect des emplacements
 * déclarés ne sont pas des détails, ce sont les garanties du mécanisme.
 */
class EmailTemplateLibraryTest extends TestCase
{
    private EmailTemplateLibrary $library;

    protected function setUp(): void
    {
        $this->library = new EmailTemplateLibrary();
    }

    public function testWebinarTemplateIsShippedAndComplete(): void
    {
        $template = $this->library->get('webinar');

        $this->assertNotNull($template, 'Le template webinar doit etre livre avec le plugin.');
        $this->assertNotSame('', $template->html, 'Le HTML compile doit etre present (dev/build-templates.sh).');
        $this->assertNotNull($template->mjml, 'Le MJML source doit rester livre pour rester editable.');

        // Chaque emplacement du HTML doit etre documente, sinon le modele ne
        // saura pas quoi mettre dedans et la valeur restera vide.
        preg_match_all('/\{\{([A-Z0-9_]+)\}\}/', $template->html, $matches);

        $this->assertSame(
            [],
            array_values(array_diff(array_unique($matches[1]), $template->getPlaceholderKeys())),
            'Tout emplacement present dans le HTML doit etre decrit dans le manifeste.',
        );
    }

    public function testValuesAreEscapedBeforeReachingTheHtml(): void
    {
        $template = $this->library->get('webinar');

        $rendered = $this->library->render($template, ['HEADLINE' => '<script>alert(1)</script> & "quoted"']);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $rendered['html']);
        $this->assertStringContainsString('&lt;script&gt;', $rendered['html']);
        $this->assertStringContainsString('&amp;', $rendered['html']);
    }

    public function testDefaultsFillTheImagePlaceholders(): void
    {
        $template = $this->library->get('webinar');

        $rendered = $this->library->render($template, []);

        $this->assertStringContainsString('placehold.co', $rendered['html'], 'Le logo doit avoir une image par defaut.');
        $this->assertStringContainsString('giphy.gif', $rendered['html'], 'Le visuel d accroche doit avoir un GIF par defaut.');
    }

    public function testMissingRequiredPlaceholdersAreReported(): void
    {
        $template = $this->library->get('webinar');

        $rendered = $this->library->render($template, ['HEADLINE' => 'Demain 10h30']);

        $this->assertNotContains('HEADLINE', $rendered['missing']);
        $this->assertContains('PROBLEM', $rendered['missing']);
        $this->assertNotContains('LOGO_URL', $rendered['missing'], 'Un emplacement avec valeur par defaut n est pas obligatoire.');
    }

    public function testMauticTokensSurviveSubstitution(): void
    {
        $template = $this->library->get('webinar');

        $rendered = $this->library->render($template, []);

        foreach (['{contactfield=firstname|there}', '{contactfield=email}', '{webview_url}', '{unsubscribe_url}'] as $token) {
            $this->assertStringContainsString($token, $rendered['html'], sprintf('Le token Mautic %s doit rester intact.', $token));
        }
    }

    public function testUndeclaredBracesAreLeftUntouched(): void
    {
        $template = $this->library->get('webinar');

        $rendered = $this->library->render($template, []);

        // Le MJML documente sa propre syntaxe ({{LIKE_THIS}}) : ce n'est pas un
        // emplacement, il ne doit pas etre efface.
        $this->assertStringContainsString('{{LIKE_THIS}}', (string) $rendered['mjml']);
    }

    public function testWebinarDay0TemplateIsShippedAndComplete(): void
    {
        $template = $this->library->get('webinar-day0');

        $this->assertNotNull($template, 'Le template webinar-day0 doit etre livre avec le plugin.');
        $this->assertNotSame('', $template->html);
        $this->assertNotNull($template->mjml);

        preg_match_all('/\{\{([A-Z0-9_]+)\}\}/', $template->html, $matches);

        $this->assertSame(
            [],
            array_values(array_diff(array_unique($matches[1]), $template->getPlaceholderKeys())),
            'Tout emplacement present dans le HTML doit etre decrit dans le manifeste.',
        );
    }

    public function testHookLineAllowsLiteralLineBreakButBlocksOtherTags(): void
    {
        $template = $this->library->get('webinar-day0');

        $rendered = $this->library->render($template, [
            'HOOK' => 'Everyone wants leads.<br/>Almost nobody fixes follow-up.<img src=x onerror=alert(1)>',
        ]);

        // La normalisation produit toujours <br> (sans slash), quelle que soit
        // la forme fournie en entree.
        $this->assertStringContainsString('Everyone wants leads.<br>Almost nobody fixes follow-up.', (string) $rendered['mjml']);
        $this->assertStringNotContainsString('<img', (string) $rendered['mjml']);
    }
}
