<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\CampaignBundle\Model\CampaignModel;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Model\ListModel;
use Mautic\PageBundle\Model\PageModel;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

/**
 * Outil de lecture : permet a l'agent de decouvrir l'existant avant d'ecrire.
 */
class ListEntitiesTool extends AbstractTool
{
    public function __construct(
        private ListModel $listModel,
        private EmailModel $emailModel,
        private PageModel $pageModel,
        private CampaignModel $campaignModel,
    ) {
    }

    public function getName(): string
    {
        return 'list_entities';
    }

    public function getDescription(): string
    {
        return 'Liste les objets Mautic existants (segments, emails, landing pages, campagnes). '
            .'A utiliser systematiquement avant de creer quelque chose, pour reutiliser un objet existant '
            .'et pour recuperer les identifiants numeriques necessaires aux autres outils.';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'entity' => [
                'type'        => 'string',
                'enum'        => ['segments', 'emails', 'pages', 'campaigns'],
                'description' => "Type d'objet a lister.",
            ],
            'search' => [
                'type'        => 'string',
                'description' => 'Filtre texte optionnel sur le nom.',
            ],
            'limit' => [
                'type'        => 'integer',
                'description' => 'Nombre maximum de resultats (defaut 25, max 100).',
            ],
        ], ['entity']);
    }

    public function execute(array $arguments): array
    {
        $entity = (string) ($arguments['entity'] ?? '');
        $limit  = max(1, min(100, (int) ($arguments['limit'] ?? 25)));

        $args = [
            'start'  => 0,
            'limit'  => $limit,
            'filter' => ['string' => (string) ($arguments['search'] ?? '')],
        ];

        $model = match ($entity) {
            'segments'  => $this->listModel,
            'emails'    => $this->emailModel,
            'pages'     => $this->pageModel,
            'campaigns' => $this->campaignModel,
            default     => null,
        };

        if (null === $model) {
            return ['status' => 'error', 'error' => sprintf('Type inconnu : %s', $entity)];
        }

        $items = [];

        foreach ($model->getEntities($args) as $item) {
            if (!is_object($item)) {
                continue;
            }

            $items[] = array_filter([
                'id'          => method_exists($item, 'getId') ? $item->getId() : null,
                'name'        => method_exists($item, 'getName') ? $item->getName() : null,
                'title'       => method_exists($item, 'getTitle') ? $item->getTitle() : null,
                'alias'       => method_exists($item, 'getAlias') ? $item->getAlias() : null,
                'subject'     => method_exists($item, 'getSubject') ? $item->getSubject() : null,
                'isPublished' => method_exists($item, 'isPublished') ? $item->isPublished() : null,
            ], static fn ($value): bool => null !== $value);
        }

        return $this->ok(['entity' => $entity, 'count' => count($items), 'items' => $items]);
    }
}
