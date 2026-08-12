<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use MauticPlugin\WittyBundle\Service\Tool\EntityCatalog;
use MauticPlugin\WittyBundle\Service\Tool\Tools\UpdateEmailSettingsTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;

/**
 * Un seul outil couvre tout ce qu'update_entity (generique) et le contenu
 * HTML (update_entity_content/replace_entity_content_text) ne couvrent pas :
 * ce test verifie surtout les regles propres a chaque type de champ —
 * subject ne peut jamais etre vide (contrairement aux autres champs texte,
 * qui s'effacent en null), utm_tags se compare independamment de l'ordre des
 * cles, et publish_up/publish_down acceptent une date valide, la rejettent
 * si invalide, et se retirent avec une chaine vide.
 */
class UpdateEmailSettingsToolTest extends TestCase
{
    public function testNoChangeRequestedIsRejectedWithoutSaving(): void
    {
        $email = new Email();
        $email->setFromAddress('old@example.test');

        $model = $this->createMock(EmailModel::class);
        $model->expects($this->never())->method('saveEntity');

        $output = $this->tool($email, $model)->execute(['id' => 5]);

        $this->assertSame('error', $output['status']);
    }

    public function testUpdatesFromAddressAndFromName(): void
    {
        $email = new Email();
        $email->setFromAddress('old@example.test')->setFromName('Old Name');

        $model = $this->createMock(EmailModel::class);
        $model->expects($this->once())->method('saveEntity')->with($email);

        $output = $this->tool($email, $model)->execute([
            'id' => 5, 'from_address' => 'new@example.test', 'from_name' => 'New Name',
        ]);

        $this->assertSame('ok', $output['status']);
        $this->assertSame('new@example.test', $email->getFromAddress());
        $this->assertSame('New Name', $email->getFromName());
    }

    public function testEmptyStringClearsABlankableFieldToNull(): void
    {
        $email = new Email();
        $email->setReplyToAddress('reply@example.test');

        $model = $this->createMock(EmailModel::class);
        $model->expects($this->once())->method('saveEntity');

        $this->tool($email, $model)->execute(['id' => 5, 'reply_to_address' => '']);

        $this->assertNull($email->getReplyToAddress());
    }

    public function testEmptySubjectIsRejectedRatherThanClearingIt(): void
    {
        $email = new Email();
        $email->setSubject('Original subject');

        $model = $this->createMock(EmailModel::class);
        $model->expects($this->never())->method('saveEntity');

        $output = $this->tool($email, $model)->execute(['id' => 5, 'subject' => '   ']);

        $this->assertSame('error', $output['status']);
        $this->assertSame('Original subject', $email->getSubject());
    }

    public function testSubjectIsUpdatedWhenNonEmpty(): void
    {
        $email = new Email();
        $email->setSubject('Old subject');

        $model = $this->createMock(EmailModel::class);
        $model->expects($this->once())->method('saveEntity');

        $output = $this->tool($email, $model)->execute(['id' => 5, 'subject' => 'New subject']);

        $this->assertSame('ok', $output['status']);
        $this->assertSame('New subject', $email->getSubject());
    }

    public function testUseOwnerAsMailerIsComparedAndUpdatedIndependently(): void
    {
        $email = new Email();
        $email->setUseOwnerAsMailer(false);

        $model = $this->createMock(EmailModel::class);
        $model->expects($this->once())->method('saveEntity');

        $output = $this->tool($email, $model)->execute(['id' => 5, 'use_owner_as_mailer' => true]);

        $this->assertSame('ok', $output['status']);
        $this->assertTrue($email->getUseOwnerAsMailer());
    }

    public function testUtmTagsComparisonIgnoresKeyOrder(): void
    {
        $email = new Email();
        $email->setUtmTags(['utm_source' => 'newsletter', 'utm_campaign' => 'summer']);

        $model = $this->createMock(EmailModel::class);
        $model->expects($this->never())->method('saveEntity');

        // Memes cles/valeurs, ordre different : ne doit pas etre compte comme un changement.
        $output = $this->tool($email, $model)->execute([
            'id' => 5, 'utm_tags' => ['utm_campaign' => 'summer', 'utm_source' => 'newsletter'],
        ]);

        $this->assertSame('error', $output['status']);
    }

    public function testUtmTagsAreReplacedWholesale(): void
    {
        $email = new Email();
        $email->setUtmTags(['utm_source' => 'old']);

        $model = $this->createMock(EmailModel::class);
        $model->expects($this->once())->method('saveEntity');

        $output = $this->tool($email, $model)->execute(['id' => 5, 'utm_tags' => ['utm_source' => 'new', 'utm_medium' => 'email']]);

        $this->assertSame('ok', $output['status']);
        $this->assertSame(['utm_medium' => 'email', 'utm_source' => 'new'], $email->getUtmTags());
    }

    public function testEmptyUtmTagsObjectClearsAllTags(): void
    {
        $email = new Email();
        $email->setUtmTags(['utm_source' => 'old']);

        $model = $this->createMock(EmailModel::class);
        $model->expects($this->once())->method('saveEntity');

        $this->tool($email, $model)->execute(['id' => 5, 'utm_tags' => []]);

        $this->assertSame([], $email->getUtmTags());
    }

    public function testInvalidPublishUpDateIsRejected(): void
    {
        $email = new Email();

        $model = $this->createMock(EmailModel::class);
        $model->expects($this->never())->method('saveEntity');

        $output = $this->tool($email, $model)->execute(['id' => 5, 'publish_up' => 'not a date']);

        $this->assertSame('error', $output['status']);
    }

    public function testValidPublishUpDateIsParsedAndApplied(): void
    {
        $email = new Email();

        $model = $this->createMock(EmailModel::class);
        $model->expects($this->once())->method('saveEntity');

        $output = $this->tool($email, $model)->execute(['id' => 5, 'publish_up' => '2026-09-01 08:00:00']);

        $this->assertSame('ok', $output['status']);
        $this->assertInstanceOf(\DateTime::class, $email->getPublishUp());
        $this->assertSame('2026-09-01 08:00:00', $email->getPublishUp()->format('Y-m-d H:i:s'));
    }

    public function testEmptyPublishDownClearsIt(): void
    {
        $email = new Email();
        $email->setPublishDown(new \DateTime('2026-01-01'));

        $model = $this->createMock(EmailModel::class);
        $model->expects($this->once())->method('saveEntity');

        $this->tool($email, $model)->execute(['id' => 5, 'publish_down' => '']);

        $this->assertNull($email->getPublishDown());
    }

    public function testDeniedPermissionIsReportedWithoutSaving(): void
    {
        $email = new Email();
        $email->setFromAddress('old@example.test');

        $model = $this->createMock(EmailModel::class);
        $model->expects($this->never())->method('saveEntity');

        $catalog = $this->createMock(EntityCatalog::class);
        $catalog->method('getModel')->willReturn($model);
        $model->method('getEntity')->willReturn($email);
        $catalog->method('isAllowed')->willReturn(false);

        $config = $this->createMock(WittyConfig::class);

        $output = (new UpdateEmailSettingsTool($catalog, $config))->execute(['id' => 5, 'from_address' => 'x@example.test']);

        $this->assertSame('denied', $output['status']);
    }

    private function tool(Email $email, EmailModel $model): UpdateEmailSettingsTool
    {
        $catalog = $this->createMock(EntityCatalog::class);
        $catalog->method('getModel')->willReturn($model);
        $model->method('getEntity')->willReturn($email);
        $catalog->method('isAllowed')->willReturn(true);
        $catalog->method('describe')->willReturn('Ma campagne');
        $catalog->method('getUrl')->willReturn('/s/emails/edit/5');

        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn(false);

        return new UpdateEmailSettingsTool($catalog, $config);
    }
}
