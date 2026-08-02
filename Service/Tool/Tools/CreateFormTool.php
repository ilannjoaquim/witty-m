<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\FormBundle\Entity\Field;
use Mautic\FormBundle\Entity\Form;
use Mautic\FormBundle\Model\FormModel;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

class CreateFormTool extends AbstractTool
{
    /** Types acceptes par Mautic (Helper\FormFieldHelper). */
    private const FIELD_TYPES = [
        'text', 'email', 'textarea', 'tel', 'url', 'number', 'date', 'datetime',
        'country', 'select', 'radiogrp', 'checkboxgrp', 'hidden', 'freetext',
    ];

    public function __construct(
        private FormModel $formModel,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'create_form';
    }

    public function getDescription(): string
    {
        return 'Cree un formulaire Mautic avec ses champs. Les champs peuvent etre relies a un champ de contact '
            .'(mapped_field) pour alimenter la fiche contact a la soumission. Types disponibles : '
            .implode(', ', self::FIELD_TYPES).'.';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return 'form:forms:create';
    }

    public function getObjectType(): ?string
    {
        return 'form';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'name'        => ['type' => 'string', 'description' => 'Nom du formulaire.'],
            'description' => ['type' => 'string'],
            'form_type'   => [
                'type'        => 'string',
                'enum'        => ['standalone', 'campaign'],
                'description' => 'standalone pour un formulaire autonome, campaign pour un formulaire de campagne. Defaut standalone.',
            ],
            'post_action' => [
                'type'        => 'string',
                'enum'        => ['return', 'message', 'redirect'],
                'description' => 'Comportement apres soumission. Defaut message.',
            ],
            'post_action_property' => [
                'type'        => 'string',
                'description' => 'Message de remerciement si post_action=message, URL si post_action=redirect.',
            ],
            'is_published' => ['type' => 'boolean', 'description' => 'Defaut false.'],
            'fields'       => [
                'type'        => 'array',
                'description' => 'Champs du formulaire, dans l ordre d affichage.',
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'label'        => ['type' => 'string'],
                        'type'         => ['type' => 'string', 'enum' => self::FIELD_TYPES],
                        'alias'        => ['type' => 'string', 'description' => 'Genere depuis le label si absent.'],
                        'required'     => ['type' => 'boolean'],
                        'mapped_field' => [
                            'type'        => 'string',
                            'description' => 'Alias du champ contact alimente, ex. email, firstname, company.',
                        ],
                        'options' => [
                            'type'        => 'array',
                            'items'       => ['type' => 'string'],
                            'description' => 'Valeurs proposees, pour select, radiogrp et checkboxgrp.',
                        ],
                    ],
                    'required' => ['label', 'type'],
                ],
            ],
        ], ['name', 'fields']);
    }

    public function execute(array $arguments): array
    {
        $name   = trim((string) ($arguments['name'] ?? ''));
        $fields = array_values((array) ($arguments['fields'] ?? []));

        if ('' === $name) {
            return ['status' => 'error', 'error' => 'Le nom du formulaire est obligatoire.'];
        }

        if ([] === $fields) {
            return ['status' => 'error', 'error' => 'Un formulaire sans champ n a pas d interet : fournis au moins un champ.'];
        }

        foreach ($fields as $field) {
            $type = (string) ($field['type'] ?? '');

            if (!in_array($type, self::FIELD_TYPES, true)) {
                return ['status' => 'error', 'error' => sprintf('Type de champ inconnu : %s. Types acceptes : %s', $type, implode(', ', self::FIELD_TYPES))];
            }
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'   => 'form',
                'name'   => $name,
                'fields' => array_map(static fn (array $f): string => sprintf(
                    '%s (%s%s)',
                    (string) ($f['label'] ?? ''),
                    (string) ($f['type'] ?? ''),
                    !empty($f['required']) ? ', obligatoire' : '',
                ), $fields),
            ]);
        }

        $postAction = (string) ($arguments['post_action'] ?? 'message');
        $postAction = in_array($postAction, ['return', 'message', 'redirect'], true) ? $postAction : 'message';

        $form = new Form();
        $form->setName($name);
        $form->setAlias($this->slugify($name));
        $form->setDescription((string) ($arguments['description'] ?? ''));
        $form->setFormType('campaign' === ($arguments['form_type'] ?? 'standalone') ? 'campaign' : 'standalone');
        $form->setPostAction($postAction);
        $form->setIsPublished((bool) ($arguments['is_published'] ?? false));

        // Mautic exige une valeur pour message et redirect ; un formulaire
        // enregistre sans elle serait invalide a l'ouverture dans le builder.
        $property = (string) ($arguments['post_action_property'] ?? '');

        if ('message' === $postAction) {
            $form->setPostActionProperty('' !== $property ? $property : 'Merci pour votre envoi.');
        } elseif ('redirect' === $postAction) {
            if ('' === $property) {
                return ['status' => 'error', 'error' => 'post_action_property (URL) est obligatoire avec post_action=redirect.'];
            }

            $form->setPostActionProperty($property);
        }

        $created = [];

        foreach ($fields as $index => $definition) {
            $label = trim((string) ($definition['label'] ?? ''));
            $alias = $this->slugify((string) ($definition['alias'] ?? $label), 'field');

            $field = new Field();
            $field->setLabel($label);
            $field->setAlias($alias);
            $field->setType((string) $definition['type']);
            $field->setIsRequired((bool) ($definition['required'] ?? false));
            $field->setOrder($index + 1);
            $field->setForm($form);

            if (!empty($definition['mapped_field'])) {
                $field->setMappedObject('contact');
                $field->setMappedField((string) $definition['mapped_field']);
            }

            $options = array_values(array_filter((array) ($definition['options'] ?? [])));

            if ([] !== $options) {
                $field->setProperties([
                    'syncList' => 0,
                    'list'     => array_map(
                        static fn (string $value): array => ['label' => $value, 'value' => $value],
                        array_map('strval', $options),
                    ),
                ]);
            }

            $form->addField($alias, $field);
            $created[] = ['label' => $label, 'alias' => $alias, 'type' => $field->getType()];
        }

        $this->formModel->saveEntity($form);

        return $this->ok([
            'id'     => $form->getId(),
            'name'   => $form->getName(),
            'alias'  => $form->getAlias(),
            'fields' => $created,
            'url'    => '/s/forms/edit/'.$form->getId(),
            'note'   => 'Formulaire cree non publie. Ouvre-le dans le builder pour verifier la mise en page et le bouton d envoi.',
        ]);
    }

    private function slugify(string $value, string $fallbackPrefix = 'form'): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;
        $value = trim($value, '_');

        return '' !== $value ? $value : $fallbackPrefix.'_'.substr((string) time(), -6);
    }
}
