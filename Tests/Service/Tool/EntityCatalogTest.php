<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool;

use Mautic\AssetBundle\Model\AssetModel;
use Mautic\CampaignBundle\Model\CampaignModel;
use Mautic\CategoryBundle\Entity\Category;
use Mautic\CategoryBundle\Model\CategoryModel;
use Mautic\ChannelBundle\Model\MessageModel;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\DynamicContentBundle\Model\DynamicContentModel;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\FormBundle\Model\FormModel;
use Mautic\LeadBundle\Model\ListModel;
use Mautic\PageBundle\Model\PageModel;
use Mautic\PointBundle\Model\PointGroupModel;
use Mautic\PointBundle\Model\PointModel;
use Mautic\PointBundle\Model\TriggerModel;
use Mautic\ProjectBundle\Model\ProjectModel;
use Mautic\ReportBundle\Model\ReportModel;
use Mautic\StageBundle\Model\StageModel;
use MauticPlugin\WittyBundle\Service\Tool\EntityCatalog;
use PHPUnit\Framework\TestCase;

/**
 * La permission d'une categorie depend du bundle auquel elle appartient
 * (email:categories:*, page:categories:*...), contrairement a tous les autres
 * types du catalogue (flat ou own/other statiques) : c'est le seul chemin de
 * isAllowed()/isCategoryCreateAllowed() qui merite un test dedie.
 */
class EntityCatalogTest extends TestCase
{
    public function testCategoryTypesMatchTheirMauticBundleName(): void
    {
        $catalog = $this->catalog($this->createMock(CorePermissions::class));

        $this->assertSame('email', $catalog->getCategoryBundle('email'));
        $this->assertSame('dynamicContent', $catalog->getCategoryBundle('dynamic_content'), 'Divergence de casse cote Mautic : DynamicContentBundle enregistre "dynamicContent", pas "dynamic_content".');
        $this->assertSame('messages', $catalog->getCategoryBundle('message'), 'ChannelBundle enregistre "messages" (pluriel), pas "message".');
        $this->assertNull($catalog->getCategoryBundle('report'), "Report n'a pas de champ category cote Mautic core.");
        $this->assertNull($catalog->getCategoryBundle('project'), "Project n'a pas de champ category cote Mautic core.");
        $this->assertNull($catalog->getCategoryBundle('point_trigger'), "PointBundle n'enregistre pas de bundle categorie pour les triggers.");
    }

    public function testIsAllowedForACategoryChecksItsOwnBundlePermission(): void
    {
        $security = $this->createMock(CorePermissions::class);
        $security->expects($this->once())
            ->method('isGranted')
            ->with('email:categories:edit')
            ->willReturn(true);

        $category = new Category();
        $category->setBundle('email');

        $this->assertTrue($this->catalog($security)->isAllowed('category', 'edit', $category));
    }

    public function testIsAllowedFallsBackToTheGenericCategoryPermissionWithoutAnEntity(): void
    {
        $security = $this->createMock(CorePermissions::class);
        $security->expects($this->once())
            ->method('isGranted')
            ->with('category:categories:view')
            ->willReturn(true);

        $this->assertTrue($this->catalog($security)->isAllowed('category', 'view', null));
    }

    public function testCreateCategoryPermissionIsCheckedAgainstTheTargetBundleNotTheGenericOne(): void
    {
        $security = $this->createMock(CorePermissions::class);
        $security->expects($this->once())
            ->method('isGranted')
            ->with('segment:categories:create')
            ->willReturn(true);

        $this->assertTrue($this->catalog($security)->isCategoryCreateAllowed('segment'));
    }

    public function testCreateCategoryIsNeverAllowedForATypeWithoutCategorySupport(): void
    {
        $security = $this->createMock(CorePermissions::class);
        $security->expects($this->never())->method('isGranted');

        $this->assertFalse($this->catalog($security)->isCategoryCreateAllowed('report'));
    }

    private function catalog(CorePermissions $security): EntityCatalog
    {
        return new EntityCatalog(
            $this->createMock(EmailModel::class),
            $this->createMock(PageModel::class),
            $this->createMock(ListModel::class),
            $this->createMock(CampaignModel::class),
            $this->createMock(FormModel::class),
            $this->createMock(AssetModel::class),
            $this->createMock(DynamicContentModel::class),
            $this->createMock(StageModel::class),
            $this->createMock(PointModel::class),
            $this->createMock(TriggerModel::class),
            $this->createMock(PointGroupModel::class),
            $this->createMock(ReportModel::class),
            // ProjectModel est declaree `final` cote Mautic : injouable via
            // createMock() (qui sous-classe). Instance non initialisee, jamais
            // appelee par les tests ici, juste besoin d'un ProjectModel valide.
            (new \ReflectionClass(ProjectModel::class))->newInstanceWithoutConstructor(),
            $this->createMock(MessageModel::class),
            $this->createMock(CategoryModel::class),
            $security,
        );
    }
}
