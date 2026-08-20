<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Model\CampaignModel;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Model\ListModel;
use Mautic\UserBundle\Model\UserModel;
use MauticPlugin\WittyBundle\Service\Field\FieldWriteGuard;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Construit une VRAIE campagne Mautic : un graphe d'evenements (pas
 * seulement une chaine lineaire), avec decisions/conditions et
 * embranchements oui/non, et la planification horaire (heure d'envoi,
 * plage horaire, jours restreints) que Mautic sait deja faire nativement.
 *
 * Reecrit en session suite a un rapport concret : l'ancienne version ne
 * savait produire qu'une suite d'actions toutes chainees l'une a l'autre
 * (jamais de branche), sans aucun controle d'horaire (un email pouvait
 * partir un dimanche a 3h du matin), et surtout sans aucun moyen de
 * verifier si un contact a repondu/ouvert/clique un email precedent avant
 * d'enchainer une relance — exactement ce que Mautic appelle une
 * "decision", nativement disponible (email.open/email.click/email.reply,
 * EmailBundle\EventListener\CampaignSubscriber::onCampaignBuild()) mais
 * jamais exposee par cet outil.
 *
 * Modele du graphe : chaque etape reference celle dont elle depend via
 * after_step (index 0-based dans le tableau steps, jamais un id invente),
 * omis pour "juste apres l'etape precedente du tableau" (chaine lineaire
 * simple, comportement historique de l'outil preserve par defaut) ou pour
 * la toute premiere etape "directement depuis les segments source". branch
 * ('yes'|'no') est obligatoire quand after_step pointe vers une decision ou
 * une condition (laquelle des deux issues suivre), et interdit sinon (une
 * action normale n'a qu'une seule suite possible). Mautic ne permet qu'un
 * seul parent par evenement (colonne parent_id) : aucune fusion de branches
 * possible, seulement des embranchements.
 */
class CreateCampaignTool extends AbstractTool
{
    /** Types d'etape dont l'evenement Mautic reel est de categorie action (une seule suite possible). */
    private const ACTION_STEP_TYPES = ['send_email', 'add_tag', 'remove_tag', 'add_points', 'change_segments', 'update_contact_field', 'change_owner'];

    /** Decisions : UNIQUEMENT branchables juste apres un step send_email (connectionRestrictions cote Mautic). */
    private const DECISION_STEP_TYPES = ['email_opened', 'email_clicked', 'email_replied'];

    /** Conditions : branchables n'importe ou, evaluent l'etat courant du contact. */
    private const CONDITION_STEP_TYPES = ['in_segment', 'has_tag', 'field_value', 'has_points', 'is_contactable'];

    private const ALL_STEP_TYPES = [...self::ACTION_STEP_TYPES, ...self::DECISION_STEP_TYPES, ...self::CONDITION_STEP_TYPES];

    /** Sous-ensemble volontairement restreint d'operateurs Mautic (Segment\OperatorOptions) pour field_value : les plus lisibles pour un agent, pas les plus exotiques (between/regex). */
    private const FIELD_VALUE_OPERATORS = ['=' => '=', '!=' => '!=', 'gt' => 'gt', 'lt' => 'lt', 'contains' => 'contains', 'empty' => 'empty', '!empty' => '!empty'];

    public function __construct(
        private CampaignModel $campaignModel,
        private EmailModel $emailModel,
        private ListModel $listModel,
        private UserModel $userModel,
        private FieldWriteGuard $fieldWriteGuard,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'create_campaign';
    }

    public function getDescription(): string
    {
        return 'Cree une VRAIE campagne Mautic : un graphe d etapes (actions/decisions/conditions), pas seulement '
            .'une suite lineaire. Chaque etape reference celle dont elle depend via after_step (index 0-based dans '
            .'steps, omis = juste apres l etape precedente du tableau, comportement par defaut pour une sequence '
            .'simple). branch (yes/no) obligatoire uniquement quand after_step pointe vers une decision/condition : '
            .'quelle issue suivre. email_opened/email_clicked/email_replied ne peuvent etre placees QUE juste apres '
            .'un step send_email (limitation reelle de Mautic) — c est le mecanisme correct pour verifier si un '
            .'contact a repondu/ouvert/clique avant d enchainer une relance, jamais a deviner autrement. '
            .'send_hour/restricted_start_hour/restricted_stop_hour/restricted_days_of_week evitent qu un email parte '
            .'hors des heures ouvrees. Les emails references doivent exister au prealable (list_entities ou '
            .'create_email).';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return 'campaign:campaigns:create';
    }

    public function getObjectType(): ?string
    {
        return 'campaign';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'name'        => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'segment_ids' => [
                'type'        => 'array',
                'items'       => ['type' => 'integer'],
                'description' => 'Segments servant de source de contacts (point d entree du graphe).',
            ],
            'steps' => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'type' => [
                            'type'        => 'string',
                            'enum'        => self::ALL_STEP_TYPES,
                            'description' => 'Actions (une seule suite) : send_email, add_tag, remove_tag, add_points, '
                                .'change_segments, update_contact_field, change_owner. Decisions (yes/no, UNIQUEMENT '
                                .'apres un step send_email) : email_opened, email_clicked, email_replied. Conditions '
                                .'(yes/no, n importe ou) : in_segment, has_tag, field_value, has_points, is_contactable.',
                        ],
                        'name' => ['type' => 'string', 'description' => 'Libelle affiche sur le canvas.'],

                        'after_step' => [
                            'type'        => 'integer',
                            'description' => 'Index 0-based (dans ce tableau steps) de l etape dont celle-ci depend. '
                                .'Omis : juste apres l etape precedente du tableau (ou depuis les segments source si '
                                .'c est la toute premiere etape).',
                        ],
                        'branch' => [
                            'type'        => 'string',
                            'enum'        => ['yes', 'no'],
                            'description' => 'Obligatoire si after_step pointe vers une decision/condition (quelle '
                                .'issue suivre) ; interdit sinon.',
                        ],

                        // send_email
                        'email_id' => ['type' => 'integer', 'description' => 'Requis pour send_email.'],

                        // add_tag / remove_tag
                        'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Requis pour add_tag / remove_tag.'],

                        // add_points
                        'points' => ['type' => 'integer', 'description' => 'Requis pour add_points.'],

                        // change_segments
                        'add_to_segments'      => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Pour change_segments : segments a rejoindre.'],
                        'remove_from_segments' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Pour change_segments : segments a quitter.'],

                        // update_contact_field
                        'fields' => ['type' => 'object', 'description' => 'Pour update_contact_field : alias_champ_contact -> valeur.'],

                        // change_owner
                        'owner_user_id' => ['type' => 'integer', 'description' => 'Requis pour change_owner : id de l utilisateur Mautic proprietaire.'],

                        // email_clicked
                        'click_urls' => [
                            'type'        => 'array',
                            'items'       => ['type' => 'string'],
                            'description' => 'Pour email_clicked (facultatif) : restreint a ces URLs precises. Omis = n importe quel lien de l email.',
                        ],

                        // in_segment
                        'segment_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Requis pour in_segment.'],

                        // has_tag
                        'condition_tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Requis pour has_tag.'],

                        // field_value
                        'field'    => ['type' => 'string', 'description' => 'Requis pour field_value : alias du champ contact.'],
                        'operator' => ['type' => 'string', 'enum' => array_keys(self::FIELD_VALUE_OPERATORS), 'description' => 'Requis pour field_value (sauf empty/!empty).'],
                        'value'    => ['type' => 'string', 'description' => 'Requis pour field_value, sauf operator=empty/!empty.'],

                        // has_points
                        'points_operator' => ['type' => 'string', 'enum' => ['=', '!=', 'gt', 'lt'], 'description' => 'Requis pour has_points.'],
                        'points_value'    => ['type' => 'integer', 'description' => 'Requis pour has_points.'],

                        // is_contactable
                        'channels' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Pour is_contactable (facultatif). Defaut ["email"].'],

                        // Planification, applicable a TOUTE etape (action/decision/condition) :
                        'delay_days'  => ['type' => 'integer', 'description' => 'Delai avant evaluation/execution, en jours. 0 = immediat (sauf si send_hour fourni).'],
                        'delay_hours' => ['type' => 'integer', 'description' => 'Delai en heures, cumulable avec delay_days.'],
                        'send_hour'   => ['type' => 'string', 'description' => 'Heure d execution, format HH:MM (ex. "08:00"). Force le mode planifie meme sans delai.'],
                        'restricted_start_hour'   => ['type' => 'string', 'description' => 'Plage horaire autorisee, debut HH:MM. Fournir avec restricted_stop_hour.'],
                        'restricted_stop_hour'    => ['type' => 'string', 'description' => 'Plage horaire autorisee, fin HH:MM.'],
                        'restricted_days_of_week' => [
                            'type'        => 'array',
                            'items'       => ['type' => 'integer'],
                            'description' => 'Jours autorises, 1=lundi ... 7=dimanche. Ex. [1,2,3,4,5] pour jours ouvres uniquement.',
                        ],
                    ],
                    'required' => ['type'],
                ],
            ],
        ], ['name', 'segment_ids', 'steps']);
    }

    public function execute(array $arguments): array
    {
        $name       = trim((string) ($arguments['name'] ?? ''));
        $segmentIds = array_values(array_filter(array_map('intval', (array) ($arguments['segment_ids'] ?? []))));
        $steps      = array_values((array) ($arguments['steps'] ?? []));

        if ('' === $name || [] === $segmentIds || [] === $steps) {
            return ['status' => 'error', 'error' => 'name, segment_ids et steps sont obligatoires.'];
        }

        foreach ($segmentIds as $segmentId) {
            if (null === $this->listModel->getEntity($segmentId)) {
                return ['status' => 'error', 'error' => sprintf('Segment source #%d introuvable.', $segmentId)];
            }
        }

        $resolved = $this->resolveGraph($steps);

        if (is_string($resolved)) {
            return ['status' => 'error', 'error' => $resolved];
        }

        if (null !== ($error = $this->validateSteps($steps, $resolved))) {
            return ['status' => 'error', 'error' => $error];
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'     => 'campaign',
                'name'     => $name,
                'segments' => $segmentIds,
                'steps'    => array_map(
                    fn (array $step, int $index): string => $this->describeStep($step, $index, $resolved),
                    $steps,
                    array_keys($steps),
                ),
            ]);
        }

        $campaign = new Campaign();
        $campaign->setName($name);
        $campaign->setDescription((string) ($arguments['description'] ?? ''));
        $campaign->setIsPublished(false);

        $events      = [];
        $nodes       = [['id' => 'lists', 'positionX' => '796', 'positionY' => '50']];
        $connections = [];
        $positionY   = 155;

        foreach ($steps as $index => $step) {
            $key   = 'new'.$index;
            $event = $this->buildEvent($campaign, $step, $index);

            $parentIndex = $resolved[$index]['after_step'];
            $branch      = $resolved[$index]['branch'];

            if (null !== $parentIndex) {
                $parentKey = 'new'.$parentIndex;
                $event->setParent($events[$parentIndex]);

                if (null !== $branch) {
                    $event->setDecisionPath($branch);
                }

                $connections[] = [
                    'sourceId' => $parentKey,
                    'targetId' => $key,
                    'anchors'  => ['source' => $branch ?? 'bottom', 'target' => 'top'],
                ];
            } else {
                $connections[] = [
                    'sourceId' => 'lists',
                    'targetId' => $key,
                    'anchors'  => ['source' => 'leadsource', 'target' => 'top'],
                ];
            }

            if (method_exists($event, 'setTempId')) {
                $event->setTempId($key);
            }

            $campaign->addEvent($key, $event);
            $events[$index] = $event;

            $nodes[] = ['id' => $key, 'positionX' => (string) (760 + 260 * ('no' === $branch ? 1 : 0)), 'positionY' => (string) $positionY];
            $positionY += 130;
        }

        $campaign->setCanvasSettings(['nodes' => $nodes, 'connections' => $connections]);

        $this->campaignModel->setLeadSources(
            $campaign,
            ['lists' => array_combine($segmentIds, $segmentIds), 'forms' => []],
            ['lists' => [], 'forms' => []],
        );

        $this->campaignModel->saveEntity($campaign);

        return $this->ok([
            'id'    => $campaign->getId(),
            'name'  => $campaign->getName(),
            'steps' => count($steps),
            'url'   => '/s/campaigns/edit/'.$campaign->getId(),
            'note'  => 'Campagne creee non publiee. Ouvre le canvas pour verifier la mise en page avant publication.',
        ]);
    }

    /**
     * Resout after_step/branch de chaque etape en (after_step: ?int, branch: ?string),
     * en appliquant le defaut "juste apres l etape precedente du tableau" quand
     * after_step est omis. Fait AVANT toute validation de contenu : une reference
     * cassee (index inexistant, en avant, ou sur soi-meme) doit etre signalee
     * clairement plutot que provoquer une erreur PHP plus loin.
     *
     * @param array<int, array<string, mixed>> $steps
     *
     * @return array<int, array{after_step: ?int, branch: ?string}>|string un message d erreur
     */
    private function resolveGraph(array $steps): array|string
    {
        $resolved = [];

        foreach ($steps as $index => $step) {
            $type = (string) ($step['type'] ?? '');

            if (!in_array($type, self::ALL_STEP_TYPES, true)) {
                return sprintf('Etape %d : type inconnu (%s). Types acceptes : %s', $index + 1, $type, implode(', ', self::ALL_STEP_TYPES));
            }

            $afterStep = array_key_exists('after_step', $step) && null !== $step['after_step']
                ? (int) $step['after_step']
                : ($index > 0 ? $index - 1 : null);

            if (null !== $afterStep) {
                if ($afterStep < 0 || $afterStep >= $index) {
                    return sprintf(
                        'Etape %d : after_step (%d) doit pointer vers une etape DEJA DEFINIE plus haut dans le tableau (index < %d).',
                        $index + 1,
                        $afterStep,
                        $index,
                    );
                }
            }

            $branch = null;

            if (array_key_exists('branch', $step) && null !== $step['branch']) {
                $branch = 'no' === $step['branch'] ? 'no' : 'yes';
            }

            $parentType = null !== $afterStep ? (string) ($steps[$afterStep]['type'] ?? '') : null;
            $parentBranches = null !== $parentType
                ? in_array($parentType, [...self::DECISION_STEP_TYPES, ...self::CONDITION_STEP_TYPES], true)
                : false;

            if ($parentBranches && null === $branch) {
                return sprintf(
                    "Etape %d : branch ('yes' ou 'no') est obligatoire, son etape parente (%d) est une decision/condition.",
                    $index + 1,
                    $afterStep + 1,
                );
            }

            if (!$parentBranches && null !== $branch) {
                return sprintf(
                    "Etape %d : branch ne doit pas etre fourni, son etape parente n est ni une decision ni une condition (une seule suite possible).",
                    $index + 1,
                );
            }

            $resolved[$index] = ['after_step' => $afterStep, 'branch' => $branch];
        }

        return $resolved;
    }

    /**
     * @param array<int, array<string, mixed>>               $steps
     * @param array<int, array{after_step: ?int, branch: ?string}> $resolved
     */
    private function validateSteps(array $steps, array $resolved): ?string
    {
        foreach ($steps as $index => $step) {
            $type  = (string) ($step['type'] ?? '');
            $label = sprintf('Etape %d', $index + 1);

            if (in_array($type, self::DECISION_STEP_TYPES, true)) {
                $afterStep  = $resolved[$index]['after_step'];
                $parentType = null !== $afterStep ? (string) ($steps[$afterStep]['type'] ?? '') : null;

                if ('send_email' !== $parentType) {
                    return sprintf(
                        '%s (%s) : doit etre placee juste apres un step send_email (limitation reelle de Mautic, cf. '
                        .'connectionRestrictions de %s cote coeur) — son etape parente est %s.',
                        $label,
                        $type,
                        $type,
                        $parentType ?? 'les segments source (aucun parent)',
                    );
                }
            }

            if ('send_email' === $type) {
                $emailId = (int) ($step['email_id'] ?? 0);

                if (0 === $emailId || null === $this->emailModel->getEntity($emailId)) {
                    return sprintf('%s : email introuvable (email_id=%d).', $label, $emailId);
                }
            }

            if (in_array($type, ['add_tag', 'remove_tag'], true) && [] === (array) ($step['tags'] ?? [])) {
                return sprintf('%s : tags manquants.', $label);
            }

            if ('add_points' === $type && !array_key_exists('points', $step)) {
                return sprintf('%s : points manquant.', $label);
            }

            if ('change_segments' === $type) {
                $add    = array_map('intval', (array) ($step['add_to_segments'] ?? []));
                $remove = array_map('intval', (array) ($step['remove_from_segments'] ?? []));

                if ([] === $add && [] === $remove) {
                    return sprintf('%s : add_to_segments ou remove_from_segments requis.', $label);
                }

                foreach ([...$add, ...$remove] as $segmentId) {
                    if (null === $this->listModel->getEntity($segmentId)) {
                        return sprintf('%s : segment #%d introuvable.', $label, $segmentId);
                    }
                }
            }

            if ('update_contact_field' === $type) {
                $fields = (array) ($step['fields'] ?? []);

                if ([] === $fields) {
                    return sprintf('%s : fields est obligatoire et ne peut pas etre vide.', $label);
                }

                $unknown = $this->fieldWriteGuard->unknownAliases(array_keys($fields), 'lead');

                if ([] !== $unknown) {
                    return sprintf(
                        "%s : alias de champ inconnu : %s. Verifie l orthographe avec l outil list_fields (object: 'contact').",
                        $label,
                        implode(', ', $unknown),
                    );
                }
            }

            if ('change_owner' === $type) {
                $ownerId = (int) ($step['owner_user_id'] ?? 0);

                if (0 === $ownerId || null === $this->userModel->getEntity($ownerId)) {
                    return sprintf('%s : utilisateur introuvable (owner_user_id=%d).', $label, $ownerId);
                }
            }

            if ('in_segment' === $type) {
                $segmentIds = array_map('intval', (array) ($step['segment_ids'] ?? []));

                if ([] === $segmentIds) {
                    return sprintf('%s : segment_ids est obligatoire pour in_segment.', $label);
                }

                foreach ($segmentIds as $segmentId) {
                    if (null === $this->listModel->getEntity($segmentId)) {
                        return sprintf('%s : segment #%d introuvable.', $label, $segmentId);
                    }
                }
            }

            if ('has_tag' === $type && [] === (array) ($step['condition_tags'] ?? [])) {
                return sprintf('%s : condition_tags est obligatoire pour has_tag.', $label);
            }

            if ('field_value' === $type) {
                $field    = trim((string) ($step['field'] ?? ''));
                $operator = (string) ($step['operator'] ?? '');

                if ('' === $field) {
                    return sprintf('%s : field est obligatoire pour field_value.', $label);
                }

                if (!isset(self::FIELD_VALUE_OPERATORS[$operator])) {
                    return sprintf('%s : operator invalide (%s). Valeurs acceptees : %s', $label, $operator, implode(', ', array_keys(self::FIELD_VALUE_OPERATORS)));
                }

                if (!in_array($operator, ['empty', '!empty'], true) && !array_key_exists('value', $step)) {
                    return sprintf('%s : value est obligatoire pour field_value avec operator=%s.', $label, $operator);
                }

                if ([] !== $this->fieldWriteGuard->unknownAliases([$field], 'lead')) {
                    return sprintf("%s : champ inconnu (%s). Verifie l orthographe avec list_fields (object: 'contact').", $label, $field);
                }
            }

            if ('has_points' === $type && (!array_key_exists('points_operator', $step) || !array_key_exists('points_value', $step))) {
                return sprintf('%s : points_operator et points_value sont obligatoires pour has_points.', $label);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $step
     */
    private function buildEvent(Campaign $campaign, array $step, int $index): Event
    {
        $type = (string) ($step['type'] ?? '');

        $event = new Event();
        $event->setCampaign($campaign);
        $event->setEventType($this->eventTypeFor($type));
        $event->setOrder($index + 1);
        $event->setName((string) ($step['name'] ?? $this->describeStep($step, $index, [])));

        $properties = $this->propertiesFor($type, $step);
        $event->setType($this->mauticTypeFor($type));
        $event->setProperties($properties);

        if ('send_email' === $type) {
            // Sans ceci, les recoupements natifs Mautic bases sur channel/channelId
            // (ex. "cette campagne reference-t-elle l email X ?") ne trouvent pas
            // cet evenement — verifie en comparant a un export reel de campagne :
            // channel/channel_id sont des COLONNES de campaign_events, jamais
            // dans properties.
            $event->setChannel('email');
            $event->setChannelId((string) $properties['email']);
        }

        $days     = (int) ($step['delay_days'] ?? 0);
        $hours    = (int) ($step['delay_hours'] ?? 0);
        $sendHour = trim((string) ($step['send_hour'] ?? ''));

        if ($days > 0 || $hours > 0 || '' !== $sendHour) {
            $event->setTriggerMode('interval');
            $event->setTriggerInterval($days > 0 ? $days : $hours);
            $event->setTriggerIntervalUnit($days > 0 ? 'd' : 'h');
        } else {
            $event->setTriggerMode('immediate');
        }

        if ('' !== $sendHour) {
            $event->setTriggerHour($sendHour);
        }

        $restrictedStart = trim((string) ($step['restricted_start_hour'] ?? ''));
        $restrictedStop  = trim((string) ($step['restricted_stop_hour'] ?? ''));

        if ('' !== $restrictedStart && '' !== $restrictedStop) {
            $event->setTriggerRestrictedStartHour($restrictedStart);
            $event->setTriggerRestrictedStopHour($restrictedStop);
        }

        $restrictedDays = array_values(array_filter(array_map('intval', (array) ($step['restricted_days_of_week'] ?? []))));

        if ([] !== $restrictedDays) {
            $event->setTriggerRestrictedDaysOfWeek($restrictedDays);
        }

        return $event;
    }

    private function eventTypeFor(string $type): string
    {
        return match (true) {
            in_array($type, self::DECISION_STEP_TYPES, true)  => Event::TYPE_DECISION,
            in_array($type, self::CONDITION_STEP_TYPES, true) => Event::TYPE_CONDITION,
            default                                            => Event::TYPE_ACTION,
        };
    }

    private function mauticTypeFor(string $type): string
    {
        return match ($type) {
            'send_email'           => 'email.send',
            'add_tag', 'remove_tag' => 'lead.changetags',
            'add_points'           => 'lead.changepoints',
            'change_segments'      => 'lead.changelist',
            'update_contact_field' => 'lead.updatelead',
            'change_owner'         => 'lead.changeowner',
            'email_opened'         => 'email.open',
            'email_clicked'        => 'email.click',
            'email_replied'        => 'email.reply',
            'in_segment'           => 'lead.segments',
            'has_tag'              => 'lead.tags',
            'field_value'          => 'lead.field_value',
            'has_points'           => 'lead.points',
            'is_contactable'       => 'lead.dnc',
            default                => throw new \InvalidArgumentException(sprintf('Type d etape non supporte : %s', $type)),
        };
    }

    /**
     * @param array<string, mixed> $step
     *
     * @return array<string, mixed>
     */
    private function propertiesFor(string $type, array $step): array
    {
        return match ($type) {
            'send_email' => [
                'email'      => (int) $step['email_id'],
                'email_type' => 'transactional',
                'priority'   => 2,
                'attempts'   => 3,
            ],
            'add_tag' => [
                'add_tags'    => array_values((array) ($step['tags'] ?? [])),
                'remove_tags' => [],
            ],
            'remove_tag' => [
                'add_tags'    => [],
                'remove_tags' => array_values((array) ($step['tags'] ?? [])),
            ],
            'add_points' => [
                'points' => (int) ($step['points'] ?? 0),
                'group'  => null,
            ],
            'change_segments' => [
                'addToLists'      => array_map('intval', (array) ($step['add_to_segments'] ?? [])),
                'removeFromLists' => array_map('intval', (array) ($step['remove_from_segments'] ?? [])),
            ],
            'update_contact_field' => $this->fieldWriteGuard->prepare((array) ($step['fields'] ?? []), 'lead')['fields'],
            'change_owner' => [
                'owner' => (int) ($step['owner_user_id'] ?? 0),
            ],
            'email_opened', 'email_replied' => [],
            'email_clicked' => [] !== (array) ($step['click_urls'] ?? [])
                ? ['urls' => ['list' => array_values(array_map('strval', (array) $step['click_urls']))]]
                : [],
            'in_segment' => [
                'segments' => array_map('intval', (array) ($step['segment_ids'] ?? [])),
            ],
            'has_tag' => [
                'tags' => array_values((array) ($step['condition_tags'] ?? [])),
            ],
            'field_value' => [
                'field'    => (string) ($step['field'] ?? ''),
                'operator' => (string) ($step['operator'] ?? '='),
                'value'    => (string) ($step['value'] ?? ''),
            ],
            'has_points' => [
                'score'    => (int) ($step['points_value'] ?? 0),
                'operator' => (string) ($step['points_operator'] ?? '='),
                'group'    => null,
            ],
            'is_contactable' => [
                'channels' => [] !== (array) ($step['channels'] ?? []) ? array_values((array) $step['channels']) : ['email'],
                'reason'   => null,
            ],
            default => [],
        };
    }

    /**
     * @param array<string, mixed>                                  $step
     * @param array<int, array{after_step: ?int, branch: ?string}>  $resolved
     */
    private function describeStep(array $step, int $index, array $resolved): string
    {
        $delay = '';
        $days  = (int) ($step['delay_days'] ?? 0);
        $hours = (int) ($step['delay_hours'] ?? 0);

        if ($days > 0) {
            $delay = sprintf(' (apres %d jour(s))', $days);
        } elseif ($hours > 0) {
            $delay = sprintf(' (apres %d heure(s))', $hours);
        }

        $branchNote = isset($resolved[$index]['branch']) && null !== $resolved[$index]['branch']
            ? sprintf(' [branche %s]', $resolved[$index]['branch'])
            : '';

        $base = match ((string) ($step['type'] ?? '')) {
            'send_email'           => sprintf('Envoyer l email #%d', (int) ($step['email_id'] ?? 0)),
            'add_tag'              => sprintf('Ajouter les tags %s', implode(', ', (array) ($step['tags'] ?? []))),
            'remove_tag'           => sprintf('Retirer les tags %s', implode(', ', (array) ($step['tags'] ?? []))),
            'add_points'           => sprintf('Ajouter %d point(s)', (int) ($step['points'] ?? 0)),
            'change_segments'      => 'Modifier l appartenance aux segments',
            'update_contact_field' => 'Mettre a jour des champs contact',
            'change_owner'         => sprintf('Changer le proprietaire (utilisateur #%d)', (int) ($step['owner_user_id'] ?? 0)),
            'email_opened'         => 'Decision : email precedent ouvert ?',
            'email_clicked'        => 'Decision : lien de l email precedent clique ?',
            'email_replied'        => 'Decision : email precedent — le contact a-t-il repondu ?',
            'in_segment'           => 'Condition : contact dans un segment',
            'has_tag'              => 'Condition : contact possede un tag',
            'field_value'          => sprintf('Condition : champ %s %s %s', (string) ($step['field'] ?? ''), (string) ($step['operator'] ?? ''), (string) ($step['value'] ?? '')),
            'has_points'           => 'Condition : score de points',
            'is_contactable'       => 'Condition : contact joignable (DNC)',
            default                => 'Etape inconnue',
        };

        return $base.$delay.$branchNote;
    }
}
