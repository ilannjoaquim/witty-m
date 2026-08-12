<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Mautic\FormBundle\Entity\Action;
use Mautic\FormBundle\Entity\Field;
use Mautic\FormBundle\Entity\Form;
use Mautic\FormBundle\Model\FormModel;
use MauticPlugin\WittyBundle\Service\Tool\EntityCatalog;
use MauticPlugin\WittyBundle\Service\Tool\Tools\ReadFormTool;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Prealable indispensable a update_form (cf. sa docblock) : ce test verifie
 * surtout que les champs et actions reviennent tries par ordre d affichage
 * (pas l ordre d insertion en base) et que les proprietes d une action sont
 * restituees telles quelles, sans reinterpretation — c est justement ce que
 * update_form reutilise pour fusionner une mise a jour partielle.
 */
class ReadFormToolTest extends TestCase
{
    public function testFieldsAndActionsAreReturnedSortedByOrder(): void
    {
        $form = new Form();
        $form->addField('email', $this->field(1, 'email', 'Email', 'email', 2));
        $form->addField('name', $this->field(2, 'name', 'Nom', 'text', 1));
        $form->addAction('a', $this->action(10, 'Notify', 'email.send.user', 2, ['user_id' => [5]]));
        $form->addAction('b', $this->action(9, 'Confirm', 'email.send.lead', 1, ['email' => 42]));

        $output = $this->tool($form)->execute(['id' => 1]);

        $this->assertSame('ok', $output['status']);
        $this->assertSame(['name', 'email'], array_column($output['fields'], 'alias'));
        $this->assertSame([9, 10], array_column($output['actions'], 'id'));
        $this->assertSame(42, $output['actions'][0]['properties']['email']);
    }

    public function testUnknownFormIsAnError(): void
    {
        $model = $this->createMock(FormModel::class);
        $model->method('getEntity')->willReturn(null);

        $catalog = $this->createMock(EntityCatalog::class);
        $catalog->method('getModel')->willReturn($model);

        $output = (new ReadFormTool($catalog))->execute(['id' => 999]);

        $this->assertSame('error', $output['status']);
    }

    public function testPermissionDeniedIsReported(): void
    {
        $output = $this->tool(new Form(), false)->execute(['id' => 1]);

        $this->assertSame('denied', $output['status']);
    }

    private function tool(Form $form, bool $allowed = true): ReadFormTool
    {
        $model = $this->createMock(FormModel::class);
        $model->method('getEntity')->willReturn($form);

        $catalog = $this->createMock(EntityCatalog::class);
        $catalog->method('getModel')->willReturn($model);
        $catalog->method('isAllowed')->willReturn($allowed);

        return new ReadFormTool($catalog);
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
