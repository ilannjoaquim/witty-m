<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\Tool\EntityCatalog;
use MauticPlugin\WittyBundle\Service\Tool\Tools\UpdateEntityContentTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;

/**
 * Avant cet outil, la seule facon de changer le HTML d'un email/page deja
 * cree etait de le supprimer et d'en recreer un autre. Deux points meritent
 * un test dedie : le refus d'ecrire dans un objet en theme visuel
 * (setCustomHtml() n'y aurait aucun effet reel) et l'apercu de confirmation
 * qui ne doit jamais contenir le HTML complet (potentiellement enorme), juste
 * sa longueur avant/apres.
 */
class UpdateEntityContentToolTest extends TestCase
{
    public function testUnknownTypeIsRejected(): void
    {
        $catalog = $this->createMock(EntityCatalog::class);

        $output = $this->tool($catalog, false)->execute(['type' => 'segment', 'id' => 1, 'html' => '<p>x</p>']);

        $this->assertSame('error', $output['status']);
    }

    public function testBlankHtmlIsRejected(): void
    {
        $catalog = $this->createMock(EntityCatalog::class);

        $output = $this->tool($catalog, false)->execute(['type' => 'email', 'id' => 1, 'html' => '   ']);

        $this->assertSame('error', $output['status']);
    }

    public function testMissingEntityIsReported(): void
    {
        [$catalog] = $this->catalogFor('email', null);

        $output = $this->tool($catalog, false)->execute(['type' => 'email', 'id' => 5, 'html' => '<p>x</p>']);

        $this->assertSame('error', $output['status']);
    }

    public function testPermissionDeniedIsReported(): void
    {
        $entity = $this->fakeEntity(template: 'blank');
        [$catalog, $model] = $this->catalogFor('email', $entity, allowed: false);

        $output = $this->tool($catalog, false)->execute(['type' => 'email', 'id' => 5, 'html' => '<p>x</p>']);

        $this->assertSame('denied', $output['status']);
        $this->assertSame(0, $model->saveCount);
    }

    public function testThemedEntityIsRefused(): void
    {
        $entity = $this->fakeEntity(template: 'webinar-day0', html: '<p>original</p>');
        [$catalog, $model] = $this->catalogFor('email', $entity);

        $output = $this->tool($catalog, false)->execute(['type' => 'email', 'id' => 5, 'html' => '<p>nouveau</p>']);

        $this->assertSame('error', $output['status']);
        $this->assertSame(0, $model->saveCount);
        $this->assertSame('<p>original</p>', $entity->getCustomHtml(), 'Le HTML existant ne doit pas bouger.');
    }

    public function testConfirmationIsRequiredAndDoesNotLeakTheFullHtml(): void
    {
        $entity = $this->fakeEntity(template: 'blank', html: '<p>original</p>');
        [$catalog, $model] = $this->catalogFor('email', $entity);

        $output = $this->tool($catalog, true)->execute([
            'type' => 'email', 'id' => 5, 'html' => str_repeat('<p>nouveau</p>', 500),
        ]);

        $this->assertSame('confirmation_required', $output['status']);
        $this->assertSame(0, $model->saveCount);
        $this->assertSame('<p>original</p>', $entity->getCustomHtml(), 'Rien ne doit etre ecrit avant confirmation.');
        $this->assertStringNotContainsString('<p>nouveau</p>', json_encode($output['preview']), 'Le HTML complet ne doit pas fuiter dans l apercu.');
        $this->assertArrayHasKey('nouvelle_longueur', $output['preview']);
    }

    public function testEmailHtmlAndSubjectAreSavedOnceConfirmed(): void
    {
        $entity = $this->fakeEntity(template: 'blank', html: '<p>original</p>', subject: 'Ancien objet');
        [$catalog, $model] = $this->catalogFor('email', $entity);

        $output = $this->tool($catalog, false)->execute([
            'type' => 'email', 'id' => 5, 'html' => '<p>nouveau</p>', 'subject' => 'Nouvel objet',
        ]);

        $this->assertSame('ok', $output['status']);
        $this->assertSame(1, $model->saveCount);
        $this->assertSame('<p>nouveau</p>', $entity->getCustomHtml());
        $this->assertSame('Nouvel objet', $entity->getSubject());
    }

    public function testSubjectIsIgnoredForAPage(): void
    {
        $entity = $this->fakeEntity(template: 'mautic_code_mode', html: '<html>original</html>', subject: 'Ne doit pas changer');
        [$catalog, $model] = $this->catalogFor('page', $entity);

        $output = $this->tool($catalog, false)->execute([
            'type' => 'page', 'id' => 5, 'html' => '<html>nouveau</html>', 'subject' => 'Ignore',
        ]);

        $this->assertSame('ok', $output['status']);
        $this->assertSame(1, $model->saveCount);
        $this->assertSame('<html>nouveau</html>', $entity->getCustomHtml());
        $this->assertSame('Ne doit pas changer', $entity->getSubject());
    }

    private function fakeEntity(string $template, string $html = '', string $subject = ''): object
    {
        return new class($template, $html, $subject) {
            public function __construct(
                private string $template,
                private string $html,
                private string $subject,
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

            public function setCustomHtml(string $html): void
            {
                $this->html = $html;
            }

            public function getSubject(): string
            {
                return $this->subject;
            }

            public function setSubject(string $subject): void
            {
                $this->subject = $subject;
            }
        };
    }

    /**
     * @return array{0: EntityCatalog, 1: object} le modele expose saveCount pour verifier l ecriture
     */
    private function catalogFor(string $type, ?object $entity, bool $allowed = true): array
    {
        $model = new class($entity) {
            public int $saveCount = 0;

            public function __construct(private ?object $entity)
            {
            }

            public function getEntity(int $id = 0): ?object
            {
                return $this->entity;
            }

            public function saveEntity(object $entity): void
            {
                ++$this->saveCount;
            }
        };

        $catalog = $this->createMock(EntityCatalog::class);
        $catalog->method('getModel')->willReturnCallback(
            static fn (string $requested): ?object => $requested === $type ? $model : null,
        );
        $catalog->method('isAllowed')->willReturn($allowed);
        $catalog->method('describe')->willReturn('Objet test');
        $catalog->method('getUrl')->willReturn('/s/x/edit/5');

        return [$catalog, $model];
    }

    private function tool(EntityCatalog $catalog, bool $requiresConfirmation): UpdateEntityContentTool
    {
        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn($requiresConfirmation);

        return new UpdateEntityContentTool($catalog, $config);
    }
}
