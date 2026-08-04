<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\CampaignExecutionEvent;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\WittyBundle\Form\Type\CreateMeetInvitationLinkActionType;
use MauticPlugin\WittyBundle\Service\PlugNmeet\Exception\PlugNmeetException;
use MauticPlugin\WittyBundle\Service\PlugNmeet\MeetInvitationCreator;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Action de campagne "Creer un lien invitation meet" : quand un contact
 * atteint ce noeud, on genere son lien d'invitation personnel vers une salle
 * plugNmeet (verifiee active a ce moment precis, pas seulement a la conception
 * de la campagne). Meme logique que l'action de formulaire homonyme
 * (EventListener/FormSubscriber.php), factorisee dans MeetInvitationCreator.
 */
class CampaignSubscriber implements EventSubscriberInterface
{
    private const ACTION_KEY = 'witty.create_meet_invitation_link';

    private const EVENT_EXECUTE = 'witty.campaign.on_create_meet_invitation_link';

    public function __construct(
        private MeetInvitationCreator $creator,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD => ['onCampaignBuild', 0],
            self::EVENT_EXECUTE               => ['onCreateInvitation', 0],
        ];
    }

    public function onCampaignBuild(CampaignBuilderEvent $event): void
    {
        $event->addAction(self::ACTION_KEY, [
            'label'       => 'mautic.witty.meet.action.create_invitation',
            'description' => 'mautic.witty.meet.action.create_invitation_descr',
            'formType'    => CreateMeetInvitationLinkActionType::class,
            'eventName'   => self::EVENT_EXECUTE,
        ]);
    }

    public function onCreateInvitation(CampaignExecutionEvent $event): void
    {
        if (!$event->checkContext(self::ACTION_KEY)) {
            return;
        }

        $lead = $event->getLead();

        if (!$lead instanceof Lead) {
            $event->setFailed('Contact introuvable.');

            return;
        }

        try {
            $this->creator->createFromActionConfig($lead, $event->getConfig());
        } catch (PlugNmeetException $e) {
            $this->logger->warning('Witty : impossible de generer un lien invitation meet.', [
                'lead_id'   => $lead->getId(),
                'exception' => $e->getMessage(),
            ]);
            $event->setFailed($e->getMessage());

            return;
        }

        $event->setResult(true);
    }
}
