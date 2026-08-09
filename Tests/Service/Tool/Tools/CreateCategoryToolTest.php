<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Mautic\CategoryBundle\Entity\Category;
use Mautic\CategoryBundle\Model\CategoryModel;
use MauticPlugin\WittyBundle\Service\Tool\EntityCatalog;
use MauticPlugin\WittyBundle\Service\Tool\Tools\CreateCategoryTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;

class CreateCategoryToolTest extends TestCase
{
    public function testRejectsAnUnknownBundle(): void
    {
        $catalog = $this->createMock(EntityCatalog::class);
        $catalog->method('getCategoryBundle')->with('not_a_type')->willReturn(null);
        $catalog->method('getCategoryTypes')->willReturn(['email', 'segment']);

        $categoryModel = $this->createMock(CategoryModel::class);
        $categoryModel->expects($this->never())->method('saveEntity');

        $tool   = $this->tool($catalog, $categoryModel, true);
        $output = $tool->execute(['title' => 'Newsletter', 'bundle' => 'not_a_type']);

        $this->assertSame('error', $output['status']);
    }

    public function testDeniedWhenTheUserLacksPermissionOnTheTargetBundle(): void
    {
        $catalog = $this->createMock(EntityCatalog::class);
        $catalog->method('getCategoryBundle')->with('email')->willReturn('email');
        $catalog->method('isCategoryCreateAllowed')->with('email')->willReturn(false);

        $categoryModel = $this->createMock(CategoryModel::class);
        $categoryModel->expects($this->never())->method('saveEntity');

        $tool   = $this->tool($catalog, $categoryModel, true);
        $output = $tool->execute(['title' => 'Newsletter', 'bundle' => 'email']);

        $this->assertSame('denied', $output['status']);
    }

    public function testRejectsAMalformedColor(): void
    {
        $catalog = $this->createMock(EntityCatalog::class);
        $catalog->method('getCategoryBundle')->with('email')->willReturn('email');
        $catalog->method('isCategoryCreateAllowed')->willReturn(true);

        $tool   = $this->tool($catalog, $this->createMock(CategoryModel::class), true);
        $output = $tool->execute(['title' => 'Newsletter', 'bundle' => 'email', 'color' => 'blue']);

        $this->assertSame('error', $output['status']);
    }

    public function testRequiresConfirmationBeforeCreating(): void
    {
        $catalog = $this->createMock(EntityCatalog::class);
        $catalog->method('getCategoryBundle')->with('email')->willReturn('email');
        $catalog->method('isCategoryCreateAllowed')->willReturn(true);

        $categoryModel = $this->createMock(CategoryModel::class);
        $categoryModel->expects($this->never())->method('saveEntity');

        $tool   = $this->tool($catalog, $categoryModel, true);
        $output = $tool->execute(['title' => 'Newsletter', 'bundle' => 'email']);

        $this->assertSame('confirmation_required', $output['status']);
        $this->assertSame('email', $output['preview']['bundle']);
    }

    public function testCreatesTheCategoryOnceConfirmed(): void
    {
        $catalog = $this->createMock(EntityCatalog::class);
        $catalog->method('getCategoryBundle')->with('segment')->willReturn('segment');
        $catalog->method('isCategoryCreateAllowed')->willReturn(true);
        $catalog->method('getUrl')->willReturn('/s/categories/category/edit/7');

        $categoryModel = $this->createMock(CategoryModel::class);
        $categoryModel->expects($this->once())
            ->method('saveEntity')
            ->with($this->callback(static function (Category $category): bool {
                return 'VIP' === $category->getTitle() && 'segment' === $category->getBundle();
            }));

        $tool   = $this->tool($catalog, $categoryModel, true);
        $output = $tool->execute(['title' => 'VIP', 'bundle' => 'segment', 'confirmed' => true]);

        $this->assertSame('ok', $output['status']);
        $this->assertSame('segment', $output['bundle']);
    }

    public function testConfirmationSkippedWhenGloballyDisabled(): void
    {
        $catalog = $this->createMock(EntityCatalog::class);
        $catalog->method('getCategoryBundle')->with('email')->willReturn('email');
        $catalog->method('isCategoryCreateAllowed')->willReturn(true);

        $categoryModel = $this->createMock(CategoryModel::class);
        $categoryModel->expects($this->once())->method('saveEntity');

        $tool   = $this->tool($catalog, $categoryModel, false);
        $output = $tool->execute(['title' => 'Newsletter', 'bundle' => 'email']);

        $this->assertSame('ok', $output['status']);
    }

    private function tool(EntityCatalog $catalog, CategoryModel $categoryModel, bool $requiresConfirmation): CreateCategoryTool
    {
        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn($requiresConfirmation);

        return new CreateCategoryTool($categoryModel, $catalog, $config);
    }
}
