<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\Tool\EntityCatalog;
use MauticPlugin\WittyBundle\Service\Tool\Tools\ReadEntityContentTool;
use PHPUnit\Framework\TestCase;

/**
 * Sans cet outil, la seule facon de "voir" le HTML d'un email/page existant
 * etait de le supprimer (perte de l'id et des stats) puis de le recreer a
 * l'aveugle. Le cas qui merite un test dedie : un objet construit avec un
 * theme visuel (pas en mode code source) doit rester lisible, mais avec un
 * avertissement explicite plutot qu'un HTML silencieusement incomplet ou
 * perime (cf. update_entity_content, qui lui refuse d'ecrire dans ce cas).
 */
class ReadEntityContentToolTest extends TestCase
{
    public function testUnknownTypeIsRejected(): void
    {
        $catalog = $this->createMock(EntityCatalog::class);

        $output = (new ReadEntityContentTool($catalog))->execute(['type' => 'segment', 'id' => 1]);

        $this->assertSame('error', $output['status']);
    }

    public function testMissingEntityIsReported(): void
    {
        $catalog = $this->catalogFor('email', null);

        $output = (new ReadEntityContentTool($catalog))->execute(['type' => 'email', 'id' => 5]);

        $this->assertSame('error', $output['status']);
    }

    public function testPermissionDeniedIsReported(): void
    {
        $entity  = $this->fakeEntity(template: 'blank');
        $catalog = $this->catalogFor('email', $entity, allowed: false);

        $output = (new ReadEntityContentTool($catalog))->execute(['type' => 'email', 'id' => 5]);

        $this->assertSame('denied', $output['status']);
    }

    public function testCodeModeEmailReturnsHtmlAndSubjectWithoutWarning(): void
    {
        $entity  = $this->fakeEntity(template: 'blank', html: '<p>Hello</p>', subject: 'Objet');
        $catalog = $this->catalogFor('email', $entity);

        $output = (new ReadEntityContentTool($catalog))->execute(['type' => 'email', 'id' => 5]);

        $this->assertSame('ok', $output['status']);
        $this->assertTrue($output['code_mode']);
        $this->assertSame('<p>Hello</p>', $output['html']);
        $this->assertSame('Objet', $output['subject']);
        $this->assertArrayNotHasKey('warning', $output);
        $this->assertArrayNotHasKey('alias', $output, 'Une page a un alias, pas un email.');
    }

    public function testCodeModePageReturnsHtmlAndAliasNotSubject(): void
    {
        $entity  = $this->fakeEntity(template: 'mautic_code_mode', html: '<html></html>', alias: 'landing');
        $catalog = $this->catalogFor('page', $entity);

        $output = (new ReadEntityContentTool($catalog))->execute(['type' => 'page', 'id' => 5]);

        $this->assertSame('ok', $output['status']);
        $this->assertTrue($output['code_mode']);
        $this->assertSame('landing', $output['alias']);
        $this->assertArrayNotHasKey('subject', $output, 'Un email a un objet, pas une page.');
    }

    public function testThemedEntityStillReturnsHtmlButWithAWarning(): void
    {
        $entity  = $this->fakeEntity(template: 'webinar-day0', html: '<p>Peut-etre perime</p>');
        $catalog = $this->catalogFor('email', $entity);

        $output = (new ReadEntityContentTool($catalog))->execute(['type' => 'email', 'id' => 5]);

        $this->assertSame('ok', $output['status']);
        $this->assertFalse($output['code_mode']);
        $this->assertSame('<p>Peut-etre perime</p>', $output['html'], 'Renvoye quand meme, a titre informatif.');
        $this->assertStringContainsString('webinar-day0', $output['warning']);
    }

    private function fakeEntity(
        string $template,
        string $html = '',
        string $subject = '',
        string $alias = '',
        bool $published = false,
    ): object {
        return new class($template, $html, $subject, $alias, $published) {
            public function __construct(
                private string $template,
                private string $html,
                private string $subject,
                private string $alias,
                private bool $published,
            ) {
            }

            public function getId(): int
            {
                return 5;
            }

            public function getName(): string
            {
                return 'Objet test';
            }

            public function getTemplate(): string
            {
                return $this->template;
            }

            public function getCustomHtml(): string
            {
                return $this->html;
            }

            public function getSubject(): string
            {
                return $this->subject;
            }

            public function getAlias(): string
            {
                return $this->alias;
            }

            public function isPublished(): bool
            {
                return $this->published;
            }
        };
    }

    private function catalogFor(string $type, ?object $entity, bool $allowed = true): EntityCatalog
    {
        $model = new class($entity) {
            public function __construct(private ?object $entity)
            {
            }

            public function getEntity(int $id = 0): ?object
            {
                return $this->entity;
            }
        };

        $catalog = $this->createMock(EntityCatalog::class);
        $catalog->method('getModel')->willReturnCallback(
            static fn (string $requested): ?object => $requested === $type ? $model : null,
        );
        $catalog->method('isAllowed')->willReturn($allowed);
        $catalog->method('describe')->willReturn('Objet test');
        $catalog->method('getUrl')->willReturn('/s/x/edit/5');

        return $catalog;
    }
}
