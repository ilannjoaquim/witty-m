<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\FormBundle\Entity\Action;
use Mautic\FormBundle\Entity\Field;
use Mautic\FormBundle\Entity\Form;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\Tool\EntityCatalog;

/**
 * Lit le detail complet d un formulaire existant : champs et actions, avec
 * leurs identifiants et leurs proprietes actuelles. Prealable indispensable
 * a update_form (impossible de cibler un champ par son alias ou une action
 * par son id sans d abord savoir lesquels existent), sur le meme principe que
 * read_entity_content pour le contenu HTML d un email/une page.
 */
class ReadFormTool extends AbstractTool
{
    public function __construct(private EntityCatalog $catalog)
    {
    }

    public function getName(): string
    {
        return 'read_form';
    }

    public function getDescription(): string
    {
        return 'Lit le detail complet d un formulaire existant : ses reglages (form_type, post_action...), ses '
            .'champs (avec alias, type, proprietes) et ses actions (avec id, type, proprietes) — necessaire avant '
            .'update_form pour connaitre l alias d un champ ou l id d une action a cibler.';
    }

    public function getObjectType(): ?string
    {
        return 'form';
    }

    public function getRequiredPermission(): ?string
    {
        return 'form:forms:viewown';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'id' => ['type' => 'integer', 'description' => 'Identifiant du formulaire.'],
        ], ['id']);
    }

    public function execute(array $arguments): array
    {
        $id   = (int) ($arguments['id'] ?? 0);
        $form = $this->catalog->getModel('form')?->getEntity($id);

        if (!$form instanceof Form) {
            return ['status' => 'error', 'error' => sprintf('Formulaire #%d introuvable.', $id)];
        }

        if (!$this->catalog->isAllowed('form', 'view', $form)) {
            return ['status' => 'denied', 'error' => sprintf('Permission de lecture refusee sur formulaire #%d.', $id)];
        }

        $fields = $form->getFields()->toArray();
        usort($fields, static fn (Field $a, Field $b): int => $a->getOrder() <=> $b->getOrder());

        $actions = $form->getActions()->toArray();
        usort($actions, static fn (Action $a, Action $b): int => $a->getOrder() <=> $b->getOrder());

        return $this->ok([
            'id'                    => $form->getId(),
            'name'                  => $form->getName(),
            'alias'                 => $form->getAlias(),
            'description'           => $form->getDescription(),
            'form_type'             => $form->getFormType(),
            'post_action'           => $form->getPostAction(),
            'post_action_property'  => $form->getPostActionProperty(),
            'is_published'          => $form->getIsPublished(),
            'fields'                => array_map(static fn (Field $field): array => [
                'id'            => $field->getId(),
                'alias'         => $field->getAlias(),
                'label'         => $field->getLabel(),
                'type'          => $field->getType(),
                'required'      => $field->isRequired(),
                'mapped_object' => $field->getMappedObject(),
                'mapped_field'  => $field->getMappedField(),
                'order'         => $field->getOrder(),
                'properties'    => $field->getProperties(),
            ], $fields),
            'actions'               => array_map(static fn (Action $action): array => [
                'id'         => $action->getId(),
                'name'       => $action->getName(),
                'type'       => $action->getType(),
                'order'      => $action->getOrder(),
                'properties' => $action->getProperties(),
            ], $actions),
            'url'                   => $this->catalog->getUrl('form', $id),
        ]);
    }
}
