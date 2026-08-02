<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Entity\LeadEventLogRepository;
use Mautic\CampaignBundle\Model\CampaignModel;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

/**
 * Statistiques d'une campagne : contacts embarques et avancement evenement
 * par evenement.
 *
 * Les compteurs viennent des memes requetes que la vue Mautic, pour que les
 * chiffres annonces par l'agent soient ceux affiches a l'ecran.
 */
class CampaignStatsTool extends AbstractTool
{
    public function __construct(
        private CampaignModel $campaignModel,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function getName(): string
    {
        return 'campaign_stats';
    }

    public function getDescription(): string
    {
        return 'Renvoie les statistiques d une campagne : nombre de contacts, et pour chaque evenement '
            .'le nombre d executions effectuees et en attente.';
    }

    public function getRequiredPermission(): ?string
    {
        return 'campaign:campaigns:viewown';
    }

    public function getObjectType(): ?string
    {
        return 'campaign';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'campaign_id' => ['type' => 'integer', 'description' => 'Identifiant de la campagne.'],
        ], ['campaign_id']);
    }

    public function execute(array $arguments): array
    {
        $id       = (int) ($arguments['campaign_id'] ?? 0);
        $campaign = $this->campaignModel->getEntity($id);

        if (!$campaign instanceof Campaign) {
            return ['status' => 'error', 'error' => sprintf('Campagne #%d introuvable.', $id)];
        }

        $leadCount = $this->campaignModel->getRepository()->getCampaignLeadCount($id);

        /** @var LeadEventLogRepository $logRepository */
        $logRepository = $this->entityManager->getRepository(LeadEventLog::class);

        // false/false/false : tous les logs. true/... : uniquement ce qui est
        // deja traite. La difference donne l'attente.
        $logged    = $logRepository->getCampaignLogCounts($id, false, false, false);
        $processed = $logRepository->getCampaignLogCounts($id, true, false, false);

        $events = [];

        foreach ($campaign->getEvents() as $event) {
            if (!$event instanceof Event) {
                continue;
            }

            $eventId        = (int) $event->getId();
            $totalLogged    = array_sum((array) ($logged[$eventId] ?? []));
            $totalProcessed = array_sum((array) ($processed[$eventId] ?? []));

            $events[] = [
                'id'        => $eventId,
                'name'      => $event->getName(),
                'type'      => $event->getType(),
                'event_type'=> $event->getEventType(),
                'processed' => $totalProcessed,
                'pending'   => max(0, $totalLogged - $totalProcessed),
            ];
        }

        usort($events, static fn (array $a, array $b): int => $b['processed'] <=> $a['processed']);

        return $this->ok([
            'id'           => $id,
            'name'         => $campaign->getName(),
            'is_published' => $campaign->isPublished(),
            'contacts'     => $leadCount,
            'events'       => $events,
            'url'          => '/s/campaigns/view/'.$id,
        ]);
    }
}
