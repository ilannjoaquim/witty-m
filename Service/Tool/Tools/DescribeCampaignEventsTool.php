<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\Campaign\CampaignEventCatalog;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

/**
 * Expose a l agent le catalogue reel des evenements de campagne installes.
 */
class DescribeCampaignEventsTool extends AbstractTool
{
    public function __construct(private CampaignEventCatalog $catalog)
    {
    }

    public function getName(): string
    {
        return 'describe_campaign_events';
    }

    public function getDescription(): string
    {
        return 'Retourne les types d evenements de campagne disponibles sur cette instance Mautic '
            .'(decisions, conditions, actions) avec la forme attendue de leurs proprietes. '
            .'A appeler avant de construire une campagne utilisant autre chose qu un envoi d email, '
            .'pour connaitre le type exact et les cles de properties a fournir.';
    }

    public function getRequiredPermission(): ?string
    {
        return 'campaign:campaigns:viewown';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'event_type' => [
                'type'        => 'string',
                'enum'        => ['decision', 'condition', 'action'],
                'description' => 'Filtre optionnel sur la categorie.',
            ],
            'search' => [
                'type'        => 'string',
                'description' => 'Filtre texte optionnel sur le type ou le libelle, ex. "email", "tag".',
            ],
            'with_properties' => [
                'type'        => 'boolean',
                'description' => 'Inclure le detail des proprietes. Defaut true. Passer false pour un simple inventaire.',
            ],
        ]);
    }

    public function execute(array $arguments): array
    {
        $catalog = $this->catalog->all(false !== ($arguments['with_properties'] ?? true));

        if (!empty($arguments['event_type'])) {
            $catalog = array_intersect_key($catalog, [(string) $arguments['event_type'] => true]);
        }

        $search = strtolower(trim((string) ($arguments['search'] ?? '')));

        if ('' !== $search) {
            foreach ($catalog as $eventType => $events) {
                $catalog[$eventType] = array_filter(
                    $events,
                    static fn (array $event, string $type): bool => str_contains(strtolower($type), $search)
                        || str_contains(strtolower((string) $event['label']), $search),
                    ARRAY_FILTER_USE_BOTH,
                );
            }

            $catalog = array_filter($catalog);
        }

        $count = array_sum(array_map('count', $catalog));

        return $this->ok(['count' => $count, 'events' => $catalog]);
    }
}
