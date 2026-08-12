<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\FormBundle\Entity\Action;
use Mautic\FormBundle\Entity\Field;
use Mautic\FormBundle\Entity\Form;
use Mautic\FormBundle\Model\FormModel;
use MauticPlugin\WittyBundle\EventListener\FormSubscriber;
use MauticPlugin\WittyBundle\Service\Form\FormDefinitions;
use MauticPlugin\WittyBundle\Service\Form\FormPropertyBuilder;
use MauticPlugin\WittyBundle\Service\PlugNmeet\MeetInvitationCreator;
use MauticPlugin\WittyBundle\Service\PlugNmeet\MeetSlotAvailabilityCalculator;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

class CreateFormTool extends AbstractTool
{
    public function __construct(
        private FormModel $formModel,
        private WittyConfig $config,
        private FormPropertyBuilder $properties,
    ) {
    }

    public function getName(): string
    {
        return 'create_form';
    }

    public function getDescription(): string
    {
        return 'Cree un formulaire Mautic avec ses champs et ses actions de soumission. Les champs peuvent etre relies a un champ de contact '
            .'ou d entreprise (mapped_field/mapped_object) pour alimenter la fiche a la soumission. Types de champ disponibles : '
            .implode(', ', FormDefinitions::FIELD_TYPES).'. Actions disponibles : '.implode(', ', FormDefinitions::ACTION_TYPES).'. '
            .'Le type '.FormSubscriber::SLOT_PICKER_FIELD_TYPE.' transforme le formulaire en calendrier de prise de rendez-vous '
            .'(cf. le parametre slot_picker) ; combine avec l action '.FormSubscriber::ACTION_KEY.', cela cree un Calendly complet : '
            .'le contact choisit un creneau, une salle plugNmeet dediee est creee a la volee et le lien d invitation est genere. '
            .'Combine avec email.send.lead, on peut aussi envoyer un email de confirmation Mautic existant a la soumission, et avec '
            .'email.send.user notifier une ou plusieurs personnes de l equipe (ex. prevenir un commercial d une nouvelle prise de rendez-vous). '
            .'Pour modifier un formulaire DEJA cree (champs/actions), utilise update_form, jamais create_form a nouveau.';
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
                        'label'    => ['type' => 'string'],
                        'type'     => ['type' => 'string', 'enum' => FormDefinitions::FIELD_TYPES],
                        'alias'    => ['type' => 'string', 'description' => 'Genere depuis le label si absent.'],
                        'required' => ['type' => 'boolean'],
                        'mapped_object' => [
                            'type'        => 'string',
                            'enum'        => ['contact', 'company'],
                            'description' => 'Objet alimente par mapped_field. Defaut contact ; company surtout utile avec le type companyLookup.',
                        ],
                        'mapped_field' => [
                            'type'        => 'string',
                            'description' => 'Alias du champ (contact ou company selon mapped_object) alimente, ex. email, firstname, company.',
                        ],
                        'options' => [
                            'type'        => 'array',
                            'items'       => ['type' => 'string'],
                            'description' => 'Valeurs proposees, pour select, radiogrp et checkboxgrp.',
                        ],
                        'html' => ['type' => 'string', 'description' => 'Pour type=freehtml : contenu HTML/texte statique affiche dans le formulaire.'],
                        'min'  => ['type' => 'integer', 'description' => 'Pour type=slider : valeur minimale. Defaut 0.'],
                        'max'  => ['type' => 'integer', 'description' => 'Pour type=slider : valeur maximale. Defaut 100.'],
                        'step' => ['type' => 'integer', 'description' => 'Pour type=slider : pas. Defaut 1.'],
                        'allowed_file_extensions' => [
                            'type'        => 'array',
                            'items'       => ['type' => 'string'],
                            'description' => 'Pour type=file : extensions autorisees, ex. ["pdf","jpg","png"]. Defaut un jeu courant de documents/images.',
                        ],
                        'allowed_file_size_mb' => ['type' => 'integer', 'description' => 'Pour type=file : taille max en Mo. Defaut 6.'],
                        'slot_picker' => [
                            'type'        => 'object',
                            'description' => 'Requis si type='.FormSubscriber::SLOT_PICKER_FIELD_TYPE.' : regle de recurrence des creneaux proposes. '
                                .'A la reservation, deux champs contact sont automatiquement renseignes (cf. EventListener/PluginSubscriber.php), '
                                .'en toutes lettres, dans la langue du formulaire (anglais par defaut, francais si le formulaire est configure en '
                                .'francais) : meeting_scheduled_organizer_at (heure formatee dans le fuseau "timezone" ci-dessous, ex. "Monday 10 '
                                .'August 2026 at 09:00 (UTC+01:00)") et meeting_scheduled_visitor_at (meme heure, formatee dans le fuseau que le '
                                .'visiteur a choisi sur le formulaire). Utilisables via {contactfield=meeting_scheduled_organizer_at} / '
                                .'{contactfield=meeting_scheduled_visitor_at} dans le contenu de n importe quel email (email.send.lead pour le '
                                .'prospect avec sa propre heure, email.send.user pour l equipe avec l heure de l organisateur, aucun calcul a faire). '
                                .'Un troisieme champ contact existe deja, meeting_scheduled_at (type datetime, une vraie date exploitable dans un '
                                .'filtre de segment ou une campagne type "3 jours avant le RDV") : pour le renseigner aussi, mets mapped_object=contact '
                                .'et mapped_field=meeting_scheduled_at sur CE champ '.FormSubscriber::SLOT_PICKER_FIELD_TYPE.' lui-meme.',
                            'properties'  => [
                                'days_of_week' => [
                                    'type'        => 'array',
                                    'items'       => ['type' => 'integer'],
                                    'description' => 'Jours ouvrables, 1=lundi ... 7=dimanche. Defaut [1,2,3,4,5].',
                                ],
                                'timezone' => [
                                    'type'        => 'string',
                                    'enum'        => array_values(MeetSlotAvailabilityCalculator::utcOffsetChoices()),
                                    'description' => 'Decalage UTC dans lequel start_time/end_time sont exprimes, ex. "+01:00". '
                                        .'Defaut '.MeetSlotAvailabilityCalculator::DEFAULT_TIMEZONE.' (UTC). '
                                        .'Cote visiteur du formulaire, le widget affiche les creneaux dans le decalage que CE dernier choisit lui-meme, independamment de celui-ci.',
                                ],
                                'start_time' => ['type' => 'string', 'description' => 'Heure de debut quotidienne, format HH:mm, dans le fuseau "timezone" ci-dessus. Defaut 09:00.'],
                                'end_time'   => ['type' => 'string', 'description' => 'Heure de fin quotidienne, format HH:mm, dans le fuseau "timezone" ci-dessus. Defaut 17:00.'],
                                'slot_duration_minutes' => ['type' => 'integer', 'description' => 'Duree d un creneau, en minutes. Defaut 30.'],
                                'buffer_days'            => ['type' => 'integer', 'description' => 'Delai de securite avant le premier creneau reservable, en jours. Defaut 1.'],
                            ],
                        ],
                    ],
                    'required' => ['label', 'type'],
                ],
            ],
            'actions' => [
                'type'        => 'array',
                'description' => 'Actions declenchees a la soumission du formulaire, dans l ordre d execution.',
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Nom interne de l action (visible dans l onglet Actions du formulaire).'],
                        'type' => ['type' => 'string', 'enum' => FormDefinitions::ACTION_TYPES],

                        // witty.create_meet_invitation_link
                        'target_field' => [
                            'type'        => 'string',
                            'enum'        => [MeetInvitationCreator::FIELD_WEBINAR, MeetInvitationCreator::FIELD_MEETING],
                            'description' => 'Pour '.FormSubscriber::ACTION_KEY.' : champ contact qui recoit le lien genere. '
                                .MeetInvitationCreator::FIELD_WEBINAR.' pour rejoindre une salle existante (webinaire planifie), '
                                .MeetInvitationCreator::FIELD_MEETING.' pour un rendez-vous 1-a-1 (typiquement avec un champ '
                                .FormSubscriber::SLOT_PICKER_FIELD_TYPE.' dans le meme formulaire).',
                        ],
                        'room_id' => [
                            'type'        => 'string',
                            'description' => 'Pour '.FormSubscriber::ACTION_KEY.' : salle plugNmeet existante a rejoindre. '
                                .'Omis (recommande pour un rendez-vous 1-a-1) : une nouvelle salle est creee a la volee pour chaque contact.',
                        ],

                        // email.send.lead / email.send.user
                        'email_id' => [
                            'type'        => 'integer',
                            'description' => 'Pour email.send.lead et email.send.user : ID d un email Mautic existant (ex. cree via create_email_from_template). '
                                .'email.send.lead l envoie au contact qui soumet le formulaire ; email.send.user l envoie a des utilisateurs Mautic (cf. user_ids), '
                                .'typiquement pour notifier l equipe interne d une nouvelle soumission/prise de rendez-vous.',
                        ],
                        'user_ids' => [
                            'type'        => 'array',
                            'items'       => ['type' => 'integer'],
                            'description' => 'Pour email.send.user (obligatoire) : IDs des utilisateurs Mautic destinataires.',
                        ],

                        // lead.changelist
                        'add_to_segments'      => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Pour lead.changelist : IDs de segments a rejoindre.'],
                        'remove_from_segments' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Pour lead.changelist : IDs de segments a quitter.'],

                        // lead.changetags
                        'add_tags'    => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Pour lead.changetags : tags a ajouter.'],
                        'remove_tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Pour lead.changetags : tags a retirer.'],

                        // lead.updatelead
                        'update_fields' => [
                            'type'        => 'object',
                            'description' => 'Pour lead.updatelead : paires alias_champ_contact => valeur a ecrire sur le contact, ex. {"lifecycle_stage": "MQL"}.',
                        ],

                        // lead.pointschange
                        'points_operator' => ['type' => 'string', 'enum' => FormDefinitions::POINTS_OPERATORS, 'description' => 'Pour lead.pointschange. Defaut plus.'],
                        'points'           => ['type' => 'integer', 'description' => 'Pour lead.pointschange : valeur appliquee avec points_operator.'],
                        'points_group_id'  => ['type' => 'integer', 'description' => 'Pour lead.pointschange : ID du groupe de points concerne (facultatif).'],

                        // form.email (email brut, pas un email Mautic)
                        'email_subject' => ['type' => 'string', 'description' => 'Pour form.email : sujet.'],
                        'email_message' => ['type' => 'string', 'description' => 'Pour form.email : corps (HTML autorise).'],
                        'email_to'      => ['type' => 'string', 'description' => 'Pour form.email : destinataires, separes par virgule.'],
                        'email_cc'      => ['type' => 'string', 'description' => 'Pour form.email : copie, separes par virgule.'],
                        'email_bcc'     => ['type' => 'string', 'description' => 'Pour form.email : copie cachee, separes par virgule.'],

                        // form.repost
                        'repost_url'          => ['type' => 'string', 'description' => 'Pour form.repost : URL qui recoit les donnees soumises (webhook).'],
                        'repost_auth_header'  => ['type' => 'string', 'description' => 'Pour form.repost : valeur de l en-tete Authorization envoye (facultatif).'],
                        'repost_failure_email' => ['type' => 'string', 'description' => 'Pour form.repost : email notifie en cas d echec (facultatif).'],
                    ],
                    'required' => ['name', 'type'],
                ],
            ],
        ], ['name', 'fields']);
    }

    public function execute(array $arguments): array
    {
        $name    = trim((string) ($arguments['name'] ?? ''));
        $fields  = array_values((array) ($arguments['fields'] ?? []));
        $actions = array_values((array) ($arguments['actions'] ?? []));

        if ('' === $name) {
            return ['status' => 'error', 'error' => 'Le nom du formulaire est obligatoire.'];
        }

        if ([] === $fields) {
            return ['status' => 'error', 'error' => 'Un formulaire sans champ n a pas d interet : fournis au moins un champ.'];
        }

        foreach ($fields as $field) {
            $type = (string) ($field['type'] ?? '');

            if (!in_array($type, FormDefinitions::FIELD_TYPES, true)) {
                return ['status' => 'error', 'error' => sprintf('Type de champ inconnu : %s. Types acceptes : %s', $type, implode(', ', FormDefinitions::FIELD_TYPES))];
            }
        }

        foreach ($actions as $action) {
            $type = (string) ($action['type'] ?? '');

            if (!in_array($type, FormDefinitions::ACTION_TYPES, true)) {
                return ['status' => 'error', 'error' => sprintf('Type d action inconnu : %s. Types acceptes : %s', $type, implode(', ', FormDefinitions::ACTION_TYPES))];
            }

            if (in_array($type, ['email.send.lead', 'email.send.user'], true) && (int) ($action['email_id'] ?? 0) <= 0) {
                return ['status' => 'error', 'error' => sprintf('email_id est obligatoire (et > 0) pour l action %s.', $type)];
            }

            if ('email.send.user' === $type && [] === array_filter((array) ($action['user_ids'] ?? []))) {
                return ['status' => 'error', 'error' => 'user_ids est obligatoire (au moins un ID) pour l action email.send.user.'];
            }

            if ('form.repost' === $type && '' === trim((string) ($action['repost_url'] ?? ''))) {
                return ['status' => 'error', 'error' => 'repost_url est obligatoire pour l action form.repost.'];
            }
        }

        // Un formulaire sans bouton n'a aucun moyen d'etre soumis (constate
        // en pratique) : on en ajoute un par defaut si l'appelant a oublie,
        // plutot que de livrer silencieusement un formulaire inutilisable.
        $hasButton = false;
        foreach ($fields as $field) {
            if ('button' === ($field['type'] ?? '')) {
                $hasButton = true;
                break;
            }
        }
        if (!$hasButton) {
            $fields[] = ['label' => 'Envoyer', 'type' => 'button'];
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'    => 'form',
                'name'    => $name,
                'fields'  => array_map(static fn (array $f): string => sprintf(
                    '%s (%s%s)',
                    (string) ($f['label'] ?? ''),
                    (string) ($f['type'] ?? ''),
                    !empty($f['required']) ? ', obligatoire' : '',
                ), $fields),
                'actions' => array_map(static fn (array $a): string => sprintf(
                    '%s (%s)',
                    (string) ($a['name'] ?? ''),
                    (string) ($a['type'] ?? ''),
                ), $actions),
            ]);
        }

        $postAction = (string) ($arguments['post_action'] ?? 'message');
        $postAction = in_array($postAction, ['return', 'message', 'redirect'], true) ? $postAction : 'message';

        $form = new Form();
        $form->setName($name);
        $form->setAlias($this->properties->slugify($name));
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
            $type  = (string) $definition['type'];
            $label = trim((string) ($definition['label'] ?? ''));
            $alias = $this->properties->slugify((string) ($definition['alias'] ?? $label), 'field');

            $field = new Field();
            $field->setLabel($label);
            $field->setAlias($alias);
            $field->setType($type);
            $field->setIsRequired((bool) ($definition['required'] ?? false));
            $field->setOrder($index + 1);
            $field->setForm($form);

            if (!empty($definition['mapped_field'])) {
                $mappedObject = (string) ($definition['mapped_object'] ?? 'contact');
                $field->setMappedObject(in_array($mappedObject, ['contact', 'company'], true) ? $mappedObject : 'contact');
                $field->setMappedField((string) $definition['mapped_field']);
            }

            if (FormSubscriber::SLOT_PICKER_FIELD_TYPE === $type) {
                // Sans ce flag, le renderer utilise le template generique
                // @MauticForm/Field/{type}.html.twig au lieu du template
                // enregistre par FormSubscriber::onFormBuilder() (constate en
                // pratique : LoaderError, template introuvable).
                $field->setIsCustom(true);
            }

            $fieldProperties = $this->properties->buildFieldProperties($type, $definition);

            if ([] !== $fieldProperties) {
                $field->setProperties($fieldProperties);
            }

            $form->addField($alias, $field);
            $created[] = ['label' => $label, 'alias' => $alias, 'type' => $type];
        }

        $createdActions = [];

        foreach ($actions as $index => $definition) {
            $type = (string) $definition['type'];

            $action = new Action();
            $action->setName(trim((string) ($definition['name'] ?? '')) ?: $type);
            $action->setType($type);
            $action->setOrder($index + 1);
            $action->setForm($form);
            $action->setProperties($this->properties->buildActionProperties($type, $definition));

            $form->addAction((string) $index, $action);
            $createdActions[] = ['name' => $action->getName(), 'type' => $type];
        }

        $this->formModel->saveEntity($form);

        return $this->ok([
            'id'      => $form->getId(),
            'name'    => $form->getName(),
            'alias'   => $form->getAlias(),
            'fields'  => $created,
            'actions' => $createdActions,
            'url'     => '/s/forms/edit/'.$form->getId(),
            'note'    => 'Formulaire cree non publie. Ouvre-le dans le builder pour verifier la mise en page et le bouton d envoi.',
        ]);
    }
}
