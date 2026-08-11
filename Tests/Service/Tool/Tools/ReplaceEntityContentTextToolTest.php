<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use MauticPlugin\WittyBundle\Service\Tool\EntityCatalog;
use MauticPlugin\WittyBundle\Service\Tool\Tools\ReplaceEntityContentTextTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A la difference d'update_entity_content, ce tool doit fonctionner sur un
 * objet en theme visuel/MJML (`getTemplate()` different de 'blank'), pas
 * seulement en mode code source : c'est tout le point de son existence (cf.
 * sa docblock). Deux cas dedies en plus des scenarios habituels : le "aucune
 * occurrence" (evite un succes silencieux qui n'a rien change) et la
 * synchronisation best-effort de la source MJML (Entity/GrapesJsBuilder)
 * quand elle existe pour un email.
 */
class ReplaceEntityContentTextToolTest extends TestCase
{
    public function testUnknownTypeIsRejected(): void
    {
        $output = $this->tool($this->createMock(EntityCatalog::class), false)->execute([
            'type' => 'segment', 'id' => 1, 'search' => 'x', 'replace' => 'y',
        ]);

        $this->assertSame('error', $output['status']);
    }

    public function testEmptySearchIsRejected(): void
    {
        $output = $this->tool($this->createMock(EntityCatalog::class), false)->execute([
            'type' => 'page', 'id' => 1, 'search' => '', 'replace' => 'y',
        ]);

        $this->assertSame('error', $output['status']);
    }

    public function testMissingEntityIsReported(): void
    {
        $catalog = $this->catalogFor('page', null);

        $output = $this->tool($catalog, false)->execute(['type' => 'page', 'id' => 5, 'search' => 'x', 'replace' => 'y']);

        $this->assertSame('error', $output['status']);
    }

    public function testPermissionDeniedIsReported(): void
    {
        $catalog = $this->catalogFor('page', $this->fakeEntity('<html>x</html>'), allowed: false);

        $output = $this->tool($catalog, false)->execute(['type' => 'page', 'id' => 5, 'search' => 'x', 'replace' => 'y']);

        $this->assertSame('denied', $output['status']);
    }

    public function testNoOccurrenceIsReportedAsError(): void
    {
        $catalog = $this->catalogFor('page', $this->fakeEntity('<html>rien a voir</html>'));

        $output = $this->tool($catalog, false)->execute(['type' => 'page', 'id' => 5, 'search' => 'introuvable', 'replace' => 'x']);

        $this->assertSame('error', $output['status']);
    }

    public function testWorksOnAThemedPageUnlikeUpdateEntityContent(): void
    {
        $entity  = $this->fakeEntity('<html><img src="https://placehold.co/logo.png"></html>', template: 'webinar-landing');
        $catalog = $this->catalogFor('page', $entity);

        $output = $this->tool($catalog, false)->execute([
            'type' => 'page', 'id' => 5,
            'search' => 'https://placehold.co/logo.png', 'replace' => 'https://real-logo.example.com/logo.png',
        ]);

        $this->assertSame('ok', $output['status']);
        $this->assertSame(1, $output['occurrences']);
        $this->assertStringContainsString('real-logo.example.com', $entity->getCustomHtml());
        $this->assertStringNotContainsString('placehold.co', $entity->getCustomHtml());
    }

    public function testConfirmationIsRequiredAndNothingChangesBeforeIt(): void
    {
        $entity  = $this->fakeEntity('<html>OLD_URL</html>');
        $catalog = $this->catalogFor('page', $entity);

        $output = $this->tool($catalog, true)->execute(['type' => 'page', 'id' => 5, 'search' => 'OLD_URL', 'replace' => 'NEW_URL']);

        $this->assertSame('confirmation_required', $output['status']);
        $this->assertSame('<html>OLD_URL</html>', $entity->getCustomHtml());
    }

    public function testEmailWithoutAGrapesJsSourceReportsMjmlNotSynced(): void
    {
        $entity  = $this->fakeEntity('<html>OLD_URL</html>');
        $catalog = $this->catalogFor('email', $entity);

        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $output = $this->tool($catalog, false, $entityManager)->execute([
            'type' => 'email', 'id' => 5, 'search' => 'OLD_URL', 'replace' => 'NEW_URL',
        ]);

        $this->assertSame('ok', $output['status']);
        $this->assertFalse($output['mjml_synced']);
    }

    public function testEmailWithAGrapesJsSourceGetsItSynced(): void
    {
        if (!class_exists(\MauticPlugin\GrapesJsBuilderBundle\Entity\GrapesJsBuilder::class)) {
            self::markTestSkipped('Plugin GrapesJsBuilderBundle absent de cette installation.');
        }

        $entity = $this->fakeEntity('<html>OLD_URL</html>');

        $builder = new \MauticPlugin\GrapesJsBuilderBundle\Entity\GrapesJsBuilder();
        $builder->setCustomMjml('<mjml>OLD_URL</mjml>');

        $catalog = $this->catalogFor('email', $entity);

        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findOneBy')->willReturn($builder);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->expects($this->once())->method('persist')->with($builder);
        $entityManager->expects($this->once())->method('flush');

        $output = $this->tool($catalog, false, $entityManager)->execute([
            'type' => 'email', 'id' => 5, 'search' => 'OLD_URL', 'replace' => 'NEW_URL',
        ]);

        $this->assertSame('ok', $output['status']);
        $this->assertTrue($output['mjml_synced']);
        $this->assertSame('<mjml>NEW_URL</mjml>', $builder->getCustomMjml());
        // Le HTML reellement envoye est corrige dans tous les cas, MJML synchronise ou non.
        $this->assertStringContainsString('NEW_URL', $entity->getCustomHtml());
    }

    private function fakeEntity(string $html, string $template = 'blank'): object
    {
        return new class($html, $template) {
            public function __construct(private string $html, private string $template)
            {
            }

            public function getId(): int
            {
                return 5;
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

            public function saveEntity(object $entity): void
            {
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

    private function tool(EntityCatalog $catalog, bool $requiresConfirmation, ?EntityManagerInterface $entityManager = null): ReplaceEntityContentTextTool
    {
        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn($requiresConfirmation);

        return new ReplaceEntityContentTextTool(
            $catalog,
            $config,
            $entityManager ?? $this->createMock(EntityManagerInterface::class),
            new NullLogger(),
        );
    }
}
