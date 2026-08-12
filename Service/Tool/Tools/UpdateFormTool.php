<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\FormBundle\Entity\Action;
use Mautic\FormBundle\Entity\Field;
use Mautic\FormBundle\Entity\Form;
use MauticPlugin\WittyBundle\EventListener\FormSubscriber;
use MauticPlugin\WittyBundle\Service\Form\FormDefinitions;
use MauticPlugin\WittyBundle\Service\Form\FormPropertyBuilder;
use MauticPlugin\WittyBundle\Service\PlugNmeet\MeetInvitationCreator;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\Tool\EntityCatalog;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Modifie un formulaire DEJA cree : ses champs et ses actions (ajout,
 * modification, suppression), plus form_type/post_action/post_action_property
 * (propres a Form, absents d update_entity — meme raisonnement que pour
 * update_email_settings). Sans cet outil, la seule facon de changer une
 * action existante (ex. l adresse destinataire d une action "Envoyer un
 * email") etait de supprimer le formulaire entier et d en recreer un autre,
 * perdant son id et ses eventuelles references dans une campagne — meme
 * probleme que celui deja resolu pour le contenu email/page.
 *
 * Utiliser read_form au prealable pour connaitre l alias exact d un champ ou
 * l id exact d une action a cibler : aucune des deux collections n est
 * indexee de facon stable en base (les champs sont ranges par id une fois le
 * formulaire recharge, jamais par alias, malgre ce que create_form utilise
 * en interne a la creation) — verifie empiriquement dans cette session.
 *
 * Suppression d un champ/d une action : `Form::fields`/`Form::actions` sont
 * en cascade "persist/remove/detach/merge/refresh" mais SANS orphanRemoval
 * (cf. Entity/Form.php core, absent de la config OneToMany) — retirer un
 * champ de la collection seul ne suffit donc pas a le supprimer de la base,
 * il faut explicitement EntityManagerInterface::remove() dessus avant de
 * sauvegarder, ce que fait cet outil.
 */
class UpdateFormTool extends AbstractTool
{
    private const OPS = ['add', 'update', 'remove'];

    /** Types d action dont les proprietes se reconstruisent entierement des qu un de leurs sous-champs change. */
    private const PROPERTY_REBUILDING_FIELD_ARGS = ['options', 'html', 'min', 'max', 'step', 'allowed_file_extensions', 'allowed_file_size_mb', 'slot_picker'];

    public function __construct(
        private EntityCatalog $catalog,
        private WittyConfig $config,
        private FormPropertyBuilder $properties,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function getName(): string
    {
        return 'update_form';
    }

    public function getDescription(): string
    {
        return 'Modifie un formulaire deja cree : ses champs et ses actions (ajout/modification/suppression, un '
            .'objet fields/actions par operation avec op=add|update|remove), plus form_type/post_action/'
            .'post_action_property. update_entity ne couvre que nom/description/publication/categorie (generique) : '
            .'utilise-le pour ca, cet outil pour tout le reste. Appelle read_form au prealable pour connaitre l '
            .'alias exact d un champ (op=update/remove) ou l id exact d une action (op=update/remove) a cibler : '
            .'ni l un ni l autre ne s invente. Memes types de champ/action et memes proprietes que create_form.';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return 'form:forms:editown';
    }

    public function getObjectType(): ?string
    {
        return 'form';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'id'                   => ['type' => 'integer', 'description' => 'Identifiant du formulaire.'],
            'form_type'            => ['type' => 'string', 'enum' => ['standalone', 'campaign']],
            'post_action'          => ['type' => 'string', 'enum' => ['return', 'message', 'redirect']],
            'post_action_property' => ['type' => 'string', 'description' => 'Message si post_action=message, URL si post_action=redirect.'],
            'fields'  => [
                'type'        => 'array',
                'description' => 'Une entree par champ a ajouter/modifier/supprimer.',
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'op'    => ['type' => 'string', 'enum' => self::OPS],
                        'alias' => ['type' => 'string', 'description' => 'Obligatoire pour update/remove (voir read_form). Pour add : genere depuis label si absent.'],
                        'label'    => ['type' => 'string'],
                        'type'     => ['type' => 'string', 'enum' => FormDefinitions::FIELD_TYPES, 'description' => 'Obligatoire pour add.'],
                        'required' => ['type' => 'boolean'],
                        'mapped_object' => ['type' => 'string', 'enum' => ['contact', 'company']],
                        'mapped_field'  => ['type' => 'string'],
                        'options' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Pour select/radiogrp/checkboxgrp.'],
                        'html'    => ['type' => 'string', 'description' => 'Pour freehtml.'],
                        'min'     => ['type' => 'integer', 'description' => 'Pour slider.'],
                        'max'     => ['type' => 'integer', 'description' => 'Pour slider.'],
                        'step'    => ['type' => 'integer', 'description' => 'Pour slider.'],
                        'allowed_file_extensions' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Pour file.'],
                        'allowed_file_size_mb'    => ['type' => 'integer', 'description' => 'Pour file.'],
                        'slot_picker' => ['type' => 'object', 'description' => 'Pour '.FormSubscriber::SLOT_PICKER_FIELD_TYPE.', memes proprietes que create_form.'],
                    ],
                    'required' => ['op'],
                ],
            ],
            'actions' => [
                'type'        => 'array',
                'description' => 'Une entree par action a ajouter/modifier/supprimer.',
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'op'   => ['type' => 'string', 'enum' => self::OPS],
                        'id'   => ['type' => 'integer', 'description' => 'Obligatoire pour update/remove (voir read_form).'],
                        'name' => ['type' => 'string', 'description' => 'Obligatoire pour add. Modifiable pour update.'],
                        'type' => ['type' => 'string', 'enum' => FormDefinitions::ACTION_TYPES, 'description' => 'Obligatoire pour add.'],
                        'target_field' => ['type' => 'string', 'enum' => [MeetInvitationCreator::FIELD_WEBINAR, MeetInvitationCreator::FIELD_MEETING]],
                        'room_id'      => ['type' => 'string'],
                        'email_id'     => ['type' => 'integer', 'description' => 'Pour email.send.lead/email.send.user.'],
                        'user_ids'     => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Pour email.send.user.'],
                        'add_to_segments'      => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'remove_from_segments' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'add_tags'    => ['type' => 'array', 'items' => ['type' => 'string']],
                        'remove_tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'update_fields' => ['type' => 'object'],
                        'points_operator' => ['type' => 'string', 'enum' => FormDefinitions::POINTS_OPERATORS],
                        'points'          => ['type' => 'integer'],
                        'points_group_id' => ['type' => 'integer'],
                        'email_subject' => ['type' => 'string'],
                        'email_message' => ['type' => 'string'],
                        'email_to'      => ['type' => 'string', 'description' => 'Pour form.email : destinataires, separes par virgule.'],
                        'email_cc'      => ['type' => 'string'],
                        'email_bcc'     => ['type' => 'string'],
                        'repost_url'           => ['type' => 'string'],
                        'repost_auth_header'   => ['type' => 'string'],
                        'repost_failure_email' => ['type' => 'string'],
                    ],
                    'required' => ['op'],
                ],
            ],
        ], ['id']);
    }

    public function execute(array $arguments): array
    {
        $id   = (int) ($arguments['id'] ?? 0);
        $form = $this->catalog->getModel('form')?->getEntity($id);

        if (!$form instanceof Form) {
            return ['status' => 'error', 'error' => sprintf('Formulaire #%d introuvable.', $id)];
        }

        if (!$this->catalog->isAllowed('form', 'edit', $form)) {
            return ['status' => 'denied', 'error' => sprintf('Permission de modification refusee sur formulaire #%d.', $id)];
        }

        $summary = [];

        if (array_key_exists('form_type', $arguments)) {
            $summary['form_type'] = (string) $arguments['form_type'];
        }

        if (array_key_exists('post_action', $arguments)) {
            $summary['post_action'] = (string) $arguments['post_action'];
        }

        if (array_key_exists('post_action_property', $arguments)) {
            $summary['post_action_property'] = (string) $arguments['post_action_property'];
        }

        $fieldOps = array_values((array) ($arguments['fields'] ?? []));
        $actionOps = array_values((array) ($arguments['actions'] ?? []));

        $fieldPlan = [];

        foreach ($fieldOps as $definition) {
            $planned = $this->planField($form, (array) $definition);

            if (isset($planned['error'])) {
                return ['status' => 'error', 'error' => $planned['error']];
            }

            $fieldPlan[] = $planned;
        }

        $actionPlan = [];

        foreach ($actionOps as $definition) {
            $planned = $this->planAction($form, (array) $definition);

            if (isset($planned['error'])) {
                return ['status' => 'error', 'error' => $planned['error']];
            }

            $actionPlan[] = $planned;
        }

        if ([] === $summary && [] === $fieldPlan && [] === $actionPlan) {
            return ['status' => 'error', 'error' => 'Aucune modification demandee : fournis form_type/post_action/post_action_property, fields ou actions.'];
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'    => 'form',
                'id'      => $id,
                'objet'   => $this->catalog->describe($form),
                'reglages' => $summary,
                'fields'  => array_map(static fn (array $p): string => $p['label'], $fieldPlan),
                'actions' => array_map(static fn (array $p): string => $p['label'], $actionPlan),
            ]);
        }

        if (isset($summary['form_type'])) {
            $form->setFormType('campaign' === $summary['form_type'] ? 'campaign' : 'standalone');
        }

        if (isset($summary['post_action'])) {
            $postAction = in_array($summary['post_action'], ['return', 'message', 'redirect'], true) ? $summary['post_action'] : 'message';
            $form->setPostAction($postAction);
        }

        if (isset($summary['post_action_property'])) {
            $form->setPostActionProperty($summary['post_action_property']);
        }

        foreach ($fieldPlan as $planned) {
            $this->applyField($form, $planned);
        }

        foreach ($actionPlan as $planned) {
            $this->applyAction($form, $planned);
        }

        $this->catalog->getModel('form')->saveEntity($form);

        return $this->ok([
            'id'      => $id,
            'name'    => $this->catalog->describe($form),
            'changes' => array_merge(array_keys($summary), array_map(static fn (array $p): string => $p['label'], $fieldPlan), array_map(static fn (array $p): string => $p['label'], $actionPlan)),
            'url'     => $this->catalog->getUrl('form', $id),
        ]);
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    private function planField(Form $form, array $definition): array
    {
        $op = (string) ($definition['op'] ?? '');

        if (!in_array($op, self::OPS, true)) {
            return ['error' => sprintf('op de champ invalide : %s.', $op)];
        }

        if ('add' === $op) {
            $type  = (string) ($definition['type'] ?? '');
            $label = trim((string) ($definition['label'] ?? ''));

            if ('' === $type || !in_array($type, FormDefinitions::FIELD_TYPES, true)) {
                return ['error' => sprintf('type de champ invalide ou absent pour un ajout : %s.', $type)];
            }

            $alias = $this->properties->slugify((string) ($definition['alias'] ?? $label), 'field');

            return ['op' => 'add', 'alias' => $alias, 'type' => $type, 'definition' => $definition, 'label' => sprintf('+ champ %s (%s)', $alias, $type)];
        }

        $alias = trim((string) ($definition['alias'] ?? ''));

        if ('' === $alias) {
            return ['error' => 'alias est obligatoire pour modifier/supprimer un champ (voir read_form).'];
        }

        $field = $this->findField($form, $alias);

        if (!$field instanceof Field) {
            return ['error' => sprintf('Champ "%s" introuvable sur ce formulaire (voir read_form).', $alias)];
        }

        if ('remove' === $op) {
            return ['op' => 'remove', 'field' => $field, 'label' => sprintf('- champ %s', $alias)];
        }

        return ['op' => 'update', 'field' => $field, 'alias' => $alias, 'definition' => $definition, 'label' => sprintf('~ champ %s', $alias)];
    }

    /**
     * @param array<string, mixed> $planned
     */
    private function applyField(Form $form, array $planned): void
    {
        if ('remove' === $planned['op']) {
            /** @var Field $field */
            $field = $planned['field'];
            $this->entityManager->remove($field);
            $form->removeField($field->getAlias(), $field);

            return;
        }

        $definition = $planned['definition'];

        if ('add' === $planned['op']) {
            $type  = $planned['type'];
            $alias = $planned['alias'];

            $maxOrder = 0;
            foreach ($form->getFields() as $existing) {
                $maxOrder = max($maxOrder, $existing->getOrder());
            }

            $field = new Field();
            $field->setLabel(trim((string) ($definition['label'] ?? '')));
            $field->setAlias($alias);
            $field->setType($type);
            $field->setIsRequired((bool) ($definition['required'] ?? false));
            $field->setOrder($maxOrder + 1);
            $field->setForm($form);

            if (!empty($definition['mapped_field'])) {
                $mappedObject = (string) ($definition['mapped_object'] ?? 'contact');
                $field->setMappedObject(in_array($mappedObject, ['contact', 'company'], true) ? $mappedObject : 'contact');
                $field->setMappedField((string) $definition['mapped_field']);
            }

            if (FormSubscriber::SLOT_PICKER_FIELD_TYPE === $type) {
                $field->setIsCustom(true);
            }

            $fieldProperties = $this->properties->buildFieldProperties($type, $definition);

            if ([] !== $fieldProperties) {
                $field->setProperties($fieldProperties);
            }

            $form->addField($alias, $field);

            return;
        }

        // update
        /** @var Field $field */
        $field = $planned['field'];

        if (array_key_exists('label', $definition)) {
            $field->setLabel(trim((string) $definition['label']));
        }

        if (array_key_exists('required', $definition)) {
            $field->setIsRequired((bool) $definition['required']);
        }

        if (array_key_exists('mapped_field', $definition)) {
            $mappedField = trim((string) $definition['mapped_field']);

            if ('' === $mappedField) {
                $field->setMappedObject(null);
                $field->setMappedField(null);
            } else {
                $mappedObject = (string) ($definition['mapped_object'] ?? $field->getMappedObject() ?? 'contact');
                $field->setMappedObject(in_array($mappedObject, ['contact', 'company'], true) ? $mappedObject : 'contact');
                $field->setMappedField($mappedField);
            }
        }

        $effectiveType = array_key_exists('type', $definition) ? (string) $definition['type'] : $field->getType();

        if (array_key_exists('type', $definition)) {
            $field->setType($effectiveType);
        }

        $touchesProperties = array_key_exists('type', $definition)
            || [] !== array_intersect(self::PROPERTY_REBUILDING_FIELD_ARGS, array_keys($definition));

        if ($touchesProperties) {
            $fieldProperties = $this->properties->buildFieldProperties($effectiveType, $definition);

            if ([] !== $fieldProperties) {
                $field->setProperties($fieldProperties);
            }
        }
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    private function planAction(Form $form, array $definition): array
    {
        $op = (string) ($definition['op'] ?? '');

        if (!in_array($op, self::OPS, true)) {
            return ['error' => sprintf('op d action invalide : %s.', $op)];
        }

        if ('add' === $op) {
            $type = (string) ($definition['type'] ?? '');
            $name = trim((string) ($definition['name'] ?? ''));

            if ('' === $type || !in_array($type, FormDefinitions::ACTION_TYPES, true)) {
                return ['error' => sprintf('type d action invalide ou absent pour un ajout : %s.', $type)];
            }

            if ($error = $this->validateActionDefinition($type, $definition)) {
                return ['error' => $error];
            }

            return ['op' => 'add', 'type' => $type, 'name' => '' !== $name ? $name : $type, 'definition' => $definition, 'label' => sprintf('+ action %s (%s)', '' !== $name ? $name : $type, $type)];
        }

        $actionId = (int) ($definition['id'] ?? 0);

        if ($actionId <= 0) {
            return ['error' => 'id est obligatoire pour modifier/supprimer une action (voir read_form).'];
        }

        $action = $this->findAction($form, $actionId);

        if (!$action instanceof Action) {
            return ['error' => sprintf('Action #%d introuvable sur ce formulaire (voir read_form).', $actionId)];
        }

        if ('remove' === $op) {
            return ['op' => 'remove', 'action' => $action, 'label' => sprintf('- action #%d (%s)', $actionId, $action->getName())];
        }

        $effectiveType = array_key_exists('type', $definition) ? (string) $definition['type'] : $action->getType();

        // Changer le type d une action revient a la reconfigurer entierement
        // (les champs obligatoires du nouveau type ne sont probablement pas
        // ceux de l ancien) : validation stricte, comme pour un ajout. Sans
        // changement de type, une mise a jour partielle n a pas a re-fournir
        // des champs deja renseignes sur l action existante.
        if ($error = $this->validateActionDefinition($effectiveType, $definition, !array_key_exists('type', $definition))) {
            return ['error' => $error];
        }

        return ['op' => 'update', 'action' => $action, 'definition' => $definition, 'label' => sprintf('~ action #%d (%s)', $actionId, $action->getName())];
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function validateActionDefinition(string $type, array $definition, bool $partial = false): ?string
    {
        if (in_array($type, ['email.send.lead', 'email.send.user'], true) && !$partial && (int) ($definition['email_id'] ?? 0) <= 0) {
            return sprintf('email_id est obligatoire (et > 0) pour l action %s.', $type);
        }

        if ('email.send.user' === $type && !$partial && [] === array_filter((array) ($definition['user_ids'] ?? []))) {
            return 'user_ids est obligatoire (au moins un ID) pour l action email.send.user.';
        }

        if ('form.repost' === $type && !$partial && '' === trim((string) ($definition['repost_url'] ?? ''))) {
            return 'repost_url est obligatoire pour l action form.repost.';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $planned
     */
    private function applyAction(Form $form, array $planned): void
    {
        if ('remove' === $planned['op']) {
            /** @var Action $action */
            $action = $planned['action'];
            $this->entityManager->remove($action);
            $form->removeAction($action);

            return;
        }

        $definition = $planned['definition'];

        if ('add' === $planned['op']) {
            $type = $planned['type'];

            $maxOrder = 0;
            foreach ($form->getActions() as $existing) {
                $maxOrder = max($maxOrder, $existing->getOrder());
            }

            $action = new Action();
            $action->setName($planned['name']);
            $action->setType($type);
            $action->setOrder($maxOrder + 1);
            $action->setForm($form);
            $action->setProperties($this->properties->buildActionProperties($type, $definition));

            // Cle arbitraire, jamais relue : Form::addAction() ne s'en sert
            // que pour son propre journal de changements interne, la
            // collection Doctrine s'appuie sur les objets, pas sur cette cle.
            // Prefixee pour ecarter toute collision avec les cles numeriques
            // (id reel) des actions deja chargees depuis la base.
            $form->addAction('new_'.uniqid(), $action);

            return;
        }

        // update
        /** @var Action $action */
        $action = $planned['action'];

        if (array_key_exists('name', $definition)) {
            $name = trim((string) $definition['name']);

            if ('' !== $name) {
                $action->setName($name);
            }
        }

        $effectiveType = array_key_exists('type', $definition) ? (string) $definition['type'] : $action->getType();

        if (array_key_exists('type', $definition)) {
            $action->setType($effectiveType);
        }

        // Les proprietes d une action forment un tout coherent par type (ex.
        // form.email : subject/message/to/cc/bcc ensemble) : toute cle
        // reconnue pour CE type declenche une reconstruction complete a
        // partir de l existant fusionne avec ce qui est fourni, plutot qu un
        // rafistolage cle par cle qui casserait facilement la coherence.
        $relevantKeys = array_diff(array_keys($definition), ['op', 'id', 'name', 'type']);

        if ([] !== $relevantKeys || array_key_exists('type', $definition)) {
            $merged = array_merge($this->actionPropertiesAsDefinition($effectiveType, $action->getProperties()), $definition);
            $action->setProperties($this->properties->buildActionProperties($effectiveType, $merged));
        }
    }

    /**
     * Reconstruit une definition "a la create_form" a partir des proprietes
     * deja enregistrees, pour fusionner proprement avec les seuls champs que
     * l appelant fournit lors d une mise a jour partielle.
     *
     * @param array<string, mixed> $properties
     *
     * @return array<string, mixed>
     */
    private function actionPropertiesAsDefinition(string $type, array $properties): array
    {
        return match ($type) {
            FormSubscriber::ACTION_KEY => [
                'target_field' => $properties['target_field'] ?? null,
                'room_id'      => $properties['room_id'] ?? null,
            ],
            'email.send.lead' => ['email_id' => $properties['email'] ?? null],
            'email.send.user' => [
                'email_id' => $properties['useremail']['email'] ?? null,
                'user_ids' => $properties['user_id'] ?? [],
            ],
            'lead.changelist' => [
                'add_to_segments'      => $properties['addToLists'] ?? [],
                'remove_from_segments' => $properties['removeFromLists'] ?? [],
            ],
            'lead.changetags' => [
                'add_tags'    => $properties['add_tags'] ?? [],
                'remove_tags' => $properties['remove_tags'] ?? [],
            ],
            'lead.updatelead' => ['update_fields' => $properties],
            'lead.pointschange' => [
                'points_operator'  => $properties['operator'] ?? null,
                'points'           => $properties['points'] ?? null,
                'points_group_id'  => $properties['group'] ?? null,
            ],
            'form.email' => [
                'email_subject' => $properties['subject'] ?? null,
                'email_message' => $properties['message'] ?? null,
                'email_to'      => $properties['to'] ?? null,
                'email_cc'      => $properties['cc'] ?? null,
                'email_bcc'     => $properties['bcc'] ?? null,
            ],
            'form.repost' => [
                'repost_url'           => $properties['post_url'] ?? null,
                'repost_auth_header'   => $properties['authorization_header'] ?? null,
                'repost_failure_email' => $properties['failure_email'] ?? null,
            ],
            default => [],
        };
    }

    private function findField(Form $form, string $alias): ?Field
    {
        foreach ($form->getFields() as $field) {
            if ($field->getAlias() === $alias) {
                return $field;
            }
        }

        return null;
    }

    private function findAction(Form $form, int $id): ?Action
    {
        foreach ($form->getActions() as $action) {
            if ($action->getId() === $id) {
                return $action;
            }
        }

        return null;
    }
}
