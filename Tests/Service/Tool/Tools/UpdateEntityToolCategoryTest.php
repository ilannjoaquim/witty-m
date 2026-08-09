<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Mautic\CategoryBundle\Entity\Category;
use MauticPlugin\WittyBundle\Service\Tool\EntityCatalog;
use MauticPlugin\WittyBundle\Service\Tool\Tools\UpdateEntityTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;

/**
 * category_id est le seul champ de update_entity dont la validite depend
 * d'une entite tierce (la Category vise-t-elle bien le meme bundle que
 * l'objet modifie ?) : c'est ce qui merite un test dedie, le reste (nom,
 * description, publication) est un simple method_exists deja couvert par la
 * lecture du code.
 *
 * EntityCatalog::getModel() ne renvoie qu'un ?object (pas de classe Mautic
 * precise) : on utilise donc de faux modeles maison (FakeModel ci-dessous)
 * plutot que createMock() sur une classe Mautic reelle — plus simple, et ca
 * evite deux ecueils sans rapport avec ce qu'on teste : certains Model Mautic
 * sont `final` (non doublables), et getEntity() y est type sur l'entite
 * Mautic concernee (une classe de test anonyme ne la satisferait pas).
 */
class UpdateEntityToolCategoryTest extends TestCase
{
    public function testCategoryIdIsRejectedWhenTheTypeDoesNotSupportCategories(): void
    {
        $entity = $this->categorizableEntity();
        $model  = new FakeModel($entity);

        $catalog = $this->catalogFor('point_trigger', categoryBundle: null, models: ['point_trigger' => $model]);

        $output = $this->tool($catalog, true)->execute([
            'type' => 'point_trigger', 'id' => 5, 'category_id' => 3,
        ]);

        $this->assertSame('error', $output['status']);
        $this->assertSame(0, $model->saveCount);
    }

    public function testCategoryIdIsRejectedWhenTheCategoryBelongsToAnotherBundle(): void
    {
        $entity        = $this->categorizableEntity();
        $model         = new FakeModel($entity);
        $categoryModel = new FakeModel($this->category('page', 'Pages'));

        $catalog = $this->catalogFor('email', categoryBundle: 'email', models: ['email' => $model, 'category' => $categoryModel]);

        $output = $this->tool($catalog, true)->execute([
            'type' => 'email', 'id' => 5, 'category_id' => 3,
        ]);

        $this->assertSame('error', $output['status']);
        $this->assertSame(0, $model->saveCount);
    }

    public function testCategoryIsAssignedOnceConfirmed(): void
    {
        $entity        = $this->categorizableEntity();
        $category      = $this->category('email', 'Promo');
        $model         = new FakeModel($entity);
        $categoryModel = new FakeModel($category);

        $catalog = $this->catalogFor('email', categoryBundle: 'email', models: ['email' => $model, 'category' => $categoryModel]);

        $output = $this->tool($catalog, false)->execute([
            'type' => 'email', 'id' => 5, 'category_id' => 3,
        ]);

        $this->assertSame('ok', $output['status']);
        $this->assertSame($category, $entity->getCategory());
        $this->assertSame(1, $model->saveCount);
    }

    public function testCategoryIdZeroClearsTheCurrentCategory(): void
    {
        $entity = $this->categorizableEntity();
        $entity->setCategory($this->category('email', 'Ancienne'));
        $model = new FakeModel($entity);

        $catalog = $this->catalogFor('email', categoryBundle: 'email', models: ['email' => $model]);

        $output = $this->tool($catalog, false)->execute([
            'type' => 'email', 'id' => 5, 'category_id' => 0,
        ]);

        $this->assertSame('ok', $output['status']);
        $this->assertNull($entity->getCategory());
        $this->assertSame(1, $model->saveCount);
    }

    private function category(string $bundle, string $title): Category
    {
        $category = new Category();
        $category->setBundle($bundle);
        $category->setTitle($title);

        return $category;
    }

    /**
     * @return object un faux "email" minimal, avec ce dont EntityCatalog::describe()
     *                 et UpdateEntityTool ont besoin (id, name, category)
     */
    private function categorizableEntity(): object
    {
        return new class {
            private ?Category $category = null;
            private string $name        = 'Campagne';

            public function getId(): int
            {
                return 5;
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function setName(string $name): void
            {
                $this->name = $name;
            }

            public function setCategory(?Category $category): void
            {
                $this->category = $category;
            }

            public function getCategory(): ?Category
            {
                return $this->category;
            }
        };
    }

    /**
     * @param array<string, FakeModel> $models cle = argument passe a getModel()
     */
    private function catalogFor(string $type, ?string $categoryBundle, array $models): EntityCatalog
    {
        $catalog = $this->createMock(EntityCatalog::class);
        $catalog->method('supports')->with($type)->willReturn(true);
        $catalog->method('isAllowed')->willReturn(true);
        $catalog->method('describe')->willReturn('Campagne');
        $catalog->method('getCategoryBundle')->with($type)->willReturn($categoryBundle);
        $catalog->method('getUrl')->willReturn('/s/x/edit/5');
        $catalog->method('getModel')->willReturnCallback(
            static fn (string $requested): ?object => $models[$requested] ?? null,
        );

        return $catalog;
    }

    private function tool(EntityCatalog $catalog, bool $requiresConfirmation): UpdateEntityTool
    {
        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn($requiresConfirmation);

        return new UpdateEntityTool($catalog, $config);
    }
}

/**
 * Modele minimal : seuls getEntity()/saveEntity() sont utilises par
 * UpdateEntityTool, sans type Mautic precis a satisfaire.
 */
class FakeModel
{
    public int $saveCount = 0;

    public function __construct(private object $entity)
    {
    }

    public function getEntity(int $id = 0): object
    {
        return $this->entity;
    }

    public function saveEntity(object $entity): void
    {
        ++$this->saveCount;
    }
}
