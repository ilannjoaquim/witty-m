<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\FormBundle\Entity\Action;
use Mautic\FormBundle\Entity\Field;
use Mautic\FormBundle\Entity\Form;
use Mautic\FormBundle\Model\FormModel;
use MauticPlugin\WittyBundle\Service\Form\FormPropertyBuilder;
use MauticPlugin\WittyBundle\Service\Tool\EntityCatalog;
use MauticPlugin\WittyBundle\Service\Tool\Tools\UpdateFormTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Le cas qui a motive cet outil (cf. sa docblock) : editer UNE action deja
 * existante (ex. le destinataire d une action "Envoyer un email") sans
 * recreer tout le formulaire. testUpdatingAnActionRecipientPreservesOtherProperties()
 * reproduit exactement ce scenario. Les autres tests couvrent ce qui est
 * propre a un outil d edition de collections (contrairement a create_form,
 * qui ne fait que des ajouts) : cibler un alias/id inexistant, et la
 * suppression qui exige un EntityManagerInterface::remove() explicite
 * (Form::fields/actions n ont pas orphanRemoval cote Mautic core — verifie
 * dans le code source, cf. docblock de l outil).
 */
class UpdateFormToolTest extends TestCase
{
    public function testNoChangeRequestedIsRejectedWithoutSaving(): void
    {
        $model = $this->createMock(FormModel::class);
        $model->expects($this->never())->method('saveEntity');

        $output = $this->tool(new Form(), $model)->execute(['id' => 1]);

        $this->assertSame('error', $output['status']);
    }

    public function testUpdatingAnActionRecipientPreservesOtherProperties(): void
    {
        $form   = new Form();
        $action = $this->action(55, 'Send raw email', 'form.email', 1, [
            'subject' => 'Bienvenue', 'message' => 'Merci', 'to' => 'old@example.test', 'cc' => '', 'bcc' => '',
        ]);
        $form->addAction('0', $action);

        $model = $this->createMock(FormModel::class);
        $model->expects($this->once())->method('saveEntity');

        $output = $this->tool($form, $model)->execute([
            'id' => 1, 'actions' => [['op' => 'update', 'id' => 55, 'email_to' => 'new@example.test']],
        ]);

        $this->assertSame('ok', $output['status']);
        $this->assertSame('new@example.test', $action->getProperties()['to']);
        $this->assertSame('Bienvenue', $action->getProperties()['subject']);
        $this->assertSame('Merci', $action->getProperties()['message']);
    }

    public function testUpdatingAnUnknownActionIdIsRejected(): void
    {
        $model = $this->createMock(FormModel::class);
        $model->expects($this->never())->method('saveEntity');

        $output = $this->tool(new Form(), $model)->execute([
            'id' => 1, 'actions' => [['op' => 'update', 'id' => 999, 'email_to' => 'x@example.test']],
        ]);

        $this->assertSame('error', $output['status']);
    }

    public function testChangingAnActionsTypeRequiresItsNewRequiredFields(): void
    {
        $form   = new Form();
        $action = $this->action(1, 'X', 'lead.changetags', 1, ['add_tags' => ['a'], 'remove_tags' => []]);
        $form->addAction('0', $action);

        $model = $this->createMock(FormModel::class);
        $model->expects($this->never())->method('saveEntity');

        $output = $this->tool($form, $model)->execute([
            'id' => 1, 'actions' => [['op' => 'update', 'id' => 1, 'type' => 'email.send.user']],
        ]);

        $this->assertSame('error', $output['status'], 'email.send.user exige email_id/user_ids, absents ici.');
    }

    public function testAddingAFieldGeneratesAnAliasAndAppendsTheOrder(): void
    {
        $form = new Form();
        $form->addField('existing', $this->field(1, 'existing', 'Existing', 'text', 1));

        $model = $this->createMock(FormModel::class);
        $model->expects($this->once())->method('saveEntity');

        $output = $this->tool($form, $model)->execute([
            'id' => 1, 'fields' => [['op' => 'add', 'label' => 'Telephone', 'type' => 'tel']],
        ]);

        $this->assertSame('ok', $output['status']);
        $added = null;
        foreach ($form->getFields() as $f) {
            if ('telephone' === $f->getAlias()) {
                $added = $f;
            }
        }
        $this->assertInstanceOf(Field::class, $added);
        $this->assertSame(2, $added->getOrder());
    }

    public function testUpdatingAnUnknownFieldAliasIsRejected(): void
    {
        $model = $this->createMock(FormModel::class);
        $model->expects($this->never())->method('saveEntity');

        $output = $this->tool(new Form(), $model)->execute([
            'id' => 1, 'fields' => [['op' => 'update', 'alias' => 'nope', 'label' => 'x']],
        ]);

        $this->assertSame('error', $output['status']);
    }

    public function testRemovingAFieldCallsEntityManagerRemoveAndDetachesIt(): void
    {
        $form  = new Form();
        $field = $this->field(1, 'old', 'Old', 'text', 1);
        $form->addField('old', $field);

        $model = $this->createMock(FormModel::class);
        $model->expects($this->once())->method('saveEntity');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('remove')->with($field);

        $output = $this->tool($form, $model, true, $em)->execute([
            'id' => 1, 'fields' => [['op' => 'remove', 'alias' => 'old']],
        ]);

        $this->assertSame('ok', $output['status']);
        $stillThere = false;
        foreach ($form->getFields() as $f) {
            if ('old' === $f->getAlias()) {
                $stillThere = true;
            }
        }
        $this->assertFalse($stillThere);
    }

    public function testRemovingAnActionCallsEntityManagerRemove(): void
    {
        $form   = new Form();
        $action = $this->action(1, 'X', 'form.repost', 1, ['post_url' => 'https://x.test']);
        $form->addAction('0', $action);

        $model = $this->createMock(FormModel::class);
        $model->expects($this->once())->method('saveEntity');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('remove')->with($action);

        $output = $this->tool($form, $model, true, $em)->execute([
            'id' => 1, 'actions' => [['op' => 'remove', 'id' => 1]],
        ]);

        $this->assertSame('ok', $output['status']);
    }

    public function testPermissionDeniedIsReportedWithoutSaving(): void
    {
        $model = $this->createMock(FormModel::class);
        $model->expects($this->never())->method('saveEntity');

        $output = $this->tool(new Form(), $model, false)->execute(['id' => 1, 'form_type' => 'campaign']);

        $this->assertSame('denied', $output['status']);
    }

    public function testConfirmationRequiredThenApplied(): void
    {
        $form = new Form();

        $model = $this->createMock(FormModel::class);
        $model->method('getEntity')->willReturn($form);
        $model->expects($this->once())->method('saveEntity');

        $catalog = $this->createMock(EntityCatalog::class);
        $catalog->method('getModel')->willReturn($model);
        $catalog->method('isAllowed')->willReturn(true);
        $catalog->method('describe')->willReturn('Mon formulaire');
        $catalog->method('getUrl')->willReturn('/s/forms/edit/1');

        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn(true);

        $tool = new UpdateFormTool($catalog, $config, new FormPropertyBuilder(), $this->createMock(EntityManagerInterface::class));

        $output = $tool->execute(['id' => 1, 'form_type' => 'campaign']);
        $this->assertSame('confirmation_required', $output['status']);

        $output2 = $tool->execute(['id' => 1, 'form_type' => 'campaign', 'confirmed' => true]);
        $this->assertSame('ok', $output2['status']);
    }

    private function tool(Form $form, FormModel $model, bool $allowed = true, ?EntityManagerInterface $em = null): UpdateFormTool
    {
        $model->method('getEntity')->willReturn($form);

        $catalog = $this->createMock(EntityCatalog::class);
        $catalog->method('getModel')->willReturn($model);
        $catalog->method('isAllowed')->willReturn($allowed);
        $catalog->method('describe')->willReturn('Mon formulaire');
        $catalog->method('getUrl')->willReturn('/s/forms/edit/1');

        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn(false);

        return new UpdateFormTool($catalog, $config, new FormPropertyBuilder(), $em ?? $this->createMock(EntityManagerInterface::class));
    }

    private function field(int $id, string $alias, string $label, string $type, int $order): Field
    {
        $field = new Field();
        (new ReflectionProperty(Field::class, 'id'))->setValue($field, $id);
        $field->setAlias($alias);
        $field->setLabel($label);
        $field->setType($type);
        $field->setOrder($order);

        return $field;
    }

    private function action(int $id, string $name, string $type, int $order, array $properties): Action
    {
        $action = new Action();
        (new ReflectionProperty(Action::class, 'id'))->setValue($action, $id);
        $action->setName($name);
        $action->setType($type);
        $action->setOrder($order);
        $action->setProperties($properties);

        return $action;
    }
}
