<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Model\CampaignModel;
use Mautic\EmailBundle\Model\EmailModel;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Construit une campagne lineaire : source = segments, puis une suite d etapes
 * chainees les unes aux autres.
 *
 * Point important du modele Mautic : une "attente" n est pas un evenement.
 * Le delai se declare sur l evenement suivant via triggerMode=interval.
 * Le schema expose donc delay_days / delay_hours par etape, pas de step "wait".
 */
class CreateCampaignTool extends AbstractTool
{
    public function __construct(
        private CampaignModel $campaignModel,
        private EmailModel $emailModel,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'create_campaign';
    }

    public function getDescription(): string
    {
        return 'Cree une campagne sequentielle alimentee par un ou plusieurs segments. '
            .'Chaque etape s execute apres la precedente, avec un delai optionnel. '
            .'Les emails referencees doivent exister au prealable (utiliser list_entities ou create_email).';
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
                'description' => 'Segments servant de source de contacts.',
            ],
            'steps' => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'type' => [
                            'type' => 'string',
                            'enum' => ['send_email', 'add_tag', 'remove_tag', 'add_points'],
                        ],
                        'name'        => ['type' => 'string', 'description' => 'Libelle affiche sur le canvas.'],
                        'email_id'    => ['type' => 'integer', 'description' => 'Requis pour send_email.'],
                        'tags'        => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Requis pour add_tag / remove_tag.'],
                        'points'      => ['type' => 'integer', 'description' => 'Requis pour add_points.'],
                        'delay_days'  => ['type' => 'integer', 'description' => 'Delai avant execution, en jours. 0 = immediat.'],
                        'delay_hours' => ['type' => 'integer', 'description' => 'Delai en heures, cumulable avec delay_days.'],
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

        if (null !== ($error = $this->validateSteps($steps))) {
            return ['status' => 'error', 'error' => $error];
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'     => 'campaign',
                'name'     => $name,
                'segments' => $segmentIds,
                'steps'    => array_map(fn (array $step): string => $this->describeStep($step), $steps),
            ]);
        }

        $campaign = new Campaign();
        $campaign->setName($name);
        $campaign->setDescription((string) ($arguments['description'] ?? ''));
        $campaign->setIsPublished(false);

        $nodes       = [['id' => 'lists', 'positionX' => '796', 'positionY' => '50']];
        $connections = [];
        $previousKey = null;
        $positionY   = 155;

        foreach ($steps as $index => $step) {
            $key   = 'new'.$index;
            $event = $this->buildEvent($campaign, $step, $index);

            if (null !== $previousKey) {
                $event->setParent($campaign->getEvents()->get($previousKey));
                $connections[] = [
                    'sourceId' => $previousKey,
                    'targetId' => $key,
                    'anchors'  => ['source' => 'bottom', 'target' => 'top'],
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

            $nodes[] = ['id' => $key, 'positionX' => '760', 'positionY' => (string) $positionY];
            $positionY += 130;
            $previousKey = $key;
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
            'note'  => 'Campagne creee non publiee. Ouvre le canvas pour verifier avant publication.',
        ]);
    }

    /**
     * @param array<string, mixed> $step
     */
    private function buildEvent(Campaign $campaign, array $step, int $index): Event
    {
        $type = (string) ($step['type'] ?? '');

        $event = new Event();
        $event->setCampaign($campaign);
        $event->setEventType(Event::TYPE_ACTION);
        $event->setOrder($index + 1);
        $event->setName((string) ($step['name'] ?? $this->describeStep($step)));

        [$eventType, $properties] = match ($type) {
            'send_email' => ['email.send', [
                'email'      => (int) $step['email_id'],
                'email_type' => 'transactional',
                'priority'   => 2,
                'attempts'   => 3,
            ]],
            'add_tag' => ['lead.changetags', [
                'add_tags'    => array_values((array) ($step['tags'] ?? [])),
                'remove_tags' => [],
            ]],
            'remove_tag' => ['lead.changetags', [
                'add_tags'    => [],
                'remove_tags' => array_values((array) ($step['tags'] ?? [])),
            ]],
            'add_points' => ['lead.changepoints', [
                'points' => (int) ($step['points'] ?? 0),
            ]],
            default => throw new \InvalidArgumentException(sprintf('Type d etape non supporte : %s', $type)),
        };

        $event->setType($eventType);
        $event->setProperties($properties);

        $days  = (int) ($step['delay_days'] ?? 0);
        $hours = (int) ($step['delay_hours'] ?? 0);

        if ($days > 0 || $hours > 0) {
            $event->setTriggerMode('interval');
            $event->setTriggerInterval($days > 0 ? $days : $hours);
            $event->setTriggerIntervalUnit($days > 0 ? 'd' : 'h');
        } else {
            $event->setTriggerMode('immediate');
        }

        return $event;
    }

    /**
     * @param array<int, array<string, mixed>> $steps
     */
    private function validateSteps(array $steps): ?string
    {
        foreach ($steps as $position => $step) {
            $type = (string) ($step['type'] ?? '');

            if ('send_email' === $type) {
                $emailId = (int) ($step['email_id'] ?? 0);

                if (0 === $emailId || null === $this->emailModel->getEntity($emailId)) {
                    return sprintf('Etape %d : email introuvable (email_id=%d).', $position + 1, $emailId);
                }
            }

            if (in_array($type, ['add_tag', 'remove_tag'], true) && [] === (array) ($step['tags'] ?? [])) {
                return sprintf('Etape %d : tags manquants.', $position + 1);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $step
     */
    private function describeStep(array $step): string
    {
        $delay = '';
        $days  = (int) ($step['delay_days'] ?? 0);
        $hours = (int) ($step['delay_hours'] ?? 0);

        if ($days > 0) {
            $delay = sprintf(' (apres %d jour(s))', $days);
        } elseif ($hours > 0) {
            $delay = sprintf(' (apres %d heure(s))', $hours);
        }

        return match ((string) ($step['type'] ?? '')) {
            'send_email' => sprintf('Envoyer l email #%d%s', (int) ($step['email_id'] ?? 0), $delay),
            'add_tag'    => sprintf('Ajouter les tags %s%s', implode(', ', (array) ($step['tags'] ?? [])), $delay),
            'remove_tag' => sprintf('Retirer les tags %s%s', implode(', ', (array) ($step['tags'] ?? [])), $delay),
            'add_points' => sprintf('Ajouter %d point(s)%s', (int) ($step['points'] ?? 0), $delay),
            default      => 'Etape inconnue',
        };
    }
}
