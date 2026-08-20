<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Entity;

use MauticPlugin\WittyBundle\Entity\WittyTemplate;
use PHPUnit\Framework\TestCase;

/**
 * Bug reel corrige en session : describePlaceholders() (ce que
 * list_email_templates/list_page_templates exposent a l'agent) omettait
 * 'context', alors qu'update_template/create_template l'attendent en entree
 * et exigent un remplacement INTEGRAL du tableau placeholders. Un
 * aller-retour lecture -> ecriture (meme pour modifier un seul champ)
 * effacait donc silencieusement le contexte js/html_br de tous les autres
 * emplacements du template, cf. Service/Template/TemplateManagerTest.php
 * pour le cote ecriture de la meme correction.
 */
class WittyTemplateTest extends TestCase
{
    public function testDescribePlaceholdersAlwaysIncludesContextEvenForTheDefault(): void
    {
        $template = new WittyTemplate();
        $template->setPlaceholders([
            ['key' => 'TITLE', 'label' => 'Titre'],
            ['key' => 'SCRIPT_VAR', 'label' => 'Variable JS', 'context' => 'js'],
        ]);

        $described = $template->describePlaceholders();

        $this->assertSame('html', $described[0]['context'], 'Un emplacement sans context stocke doit quand meme exposer le defaut explicitement.');
        $this->assertSame('js', $described[1]['context']);
    }

    public function testGetJsContextKeysStillWorksAfterAddingContextToDescribePlaceholders(): void
    {
        $template = new WittyTemplate();
        $template->setPlaceholders([
            ['key' => 'A', 'context' => 'js'],
            ['key' => 'B'],
        ]);

        $this->assertSame(['A'], $template->getJsContextKeys());
    }
}
