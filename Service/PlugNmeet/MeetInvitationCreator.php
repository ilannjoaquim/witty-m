<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\PlugNmeet;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\WittyBundle\Entity\WittyMeetInvitation;
use MauticPlugin\WittyBundle\Service\PlugNmeet\Exception\PlugNmeetException;

/**
 * Coeur de "Creer un lien invitation meet", partage par l'action de campagne
 * et l'action de formulaire (memes etapes, deux points de declenchement).
 *
 * Deux modes bien distincts, avec chacun son champ contact dedie pour ne
 * jamais les confondre :
 * - webinaire : la salle existe deja (planifiee a l'avance), on verifie juste
 *   qu'elle est active -> webinaire_invitation_link.
 * - rendez-vous : pas de salle prevue a l'avance (contrairement a un
 *   webinaire), on en cree une a la volee pour ce contact precis
 *   -> meeting_invitation_link.
 */
class MeetInvitationCreator
{
    public const FIELD_WEBINAR = 'webinaire_invitation_link';
    public const FIELD_MEETING = 'meeting_invitation_link';

    public function __construct(
        private PlugNmeetClient $client,
        private InvitationLinkSigner $signer,
        private LeadModel $leadModel,
        private EntityManagerInterface $em,
        private CoreParametersHelper $coreParametersHelper,
    ) {
    }

    /**
     * @throws PlugNmeetException si la salle n'existe pas ou n'est plus active
     */
    public function createForLead(Lead $lead, string $roomId, string $targetField = self::FIELD_WEBINAR): WittyMeetInvitation
    {
        $roomInfo  = $this->client->getActiveRoomInfo($roomId);
        $roomTitle = (string) ($roomInfo['room']['room_info']['room_title'] ?? $roomId);

        return $this->finalize($lead, $roomId, $roomTitle, $targetField);
    }

    /**
     * Cree une salle dediee a ce contact (nom "{contact} x {mailer_from_name}",
     * seul nom d'entreprise reellement configure dans Mautic) puis genere son
     * invitation. Contrairement a un webinaire, chaque appel produit une
     * nouvelle salle : pas de reutilisation entre rendez-vous.
     *
     * @throws PlugNmeetException si la creation de la salle echoue cote plugNmeet
     */
    public function createNewRoomForLead(Lead $lead, string $targetField = self::FIELD_MEETING): WittyMeetInvitation
    {
        $leadName = trim((string) $lead->getFirstname().' '.(string) $lead->getLastname());

        if ('' === $leadName) {
            $leadName = (string) ($lead->getEmail() ?? sprintf('Contact #%d', $lead->getId()));
        }

        $companyName = trim((string) $this->coreParametersHelper->get('mailer_from_name'));
        $roomTitle   = sprintf('%s x %s', $leadName, '' !== $companyName ? $companyName : 'Meeting');
        $roomId      = $this->generateRoomId($lead);

        $this->client->createRoom($roomId, ['title' => $roomTitle]);

        return $this->finalize($lead, $roomId, $roomTitle, $targetField);
    }

    /**
     * Point d'entree commun aux deux actions (campagne et formulaire) : lit
     * room_id/target_field depuis la config soumise et choisit le bon mode.
     *
     * @param array<string, mixed> $config
     *
     * @throws PlugNmeetException
     */
    public function createFromActionConfig(Lead $lead, array $config): WittyMeetInvitation
    {
        $targetField = (string) ($config['target_field'] ?? self::FIELD_WEBINAR);
        $roomId      = trim((string) ($config['room_id'] ?? ''));

        return '' !== $roomId
            ? $this->createForLead($lead, $roomId, $targetField)
            : $this->createNewRoomForLead($lead, $targetField);
    }

    private function finalize(Lead $lead, string $roomId, string $roomTitle, string $targetField): WittyMeetInvitation
    {
        $signed = $this->signer->sign((int) $lead->getId(), $roomId, $roomTitle);

        $this->leadModel->setFieldValues($lead, [$targetField => $signed['url']], false, false);
        $this->leadModel->saveEntity($lead);

        $invitation = new WittyMeetInvitation();
        $invitation->setLead($lead);
        $invitation->setRoomId($roomId);
        $invitation->setRoomTitle($roomTitle);
        $invitation->setToken($signed['token']);

        $this->em->persist($invitation);
        $this->em->flush();

        return $invitation;
    }

    private function generateRoomId(Lead $lead): string
    {
        return sprintf('meeting-%d-%s', $lead->getId(), substr(md5(uniqid('', true)), 0, 8));
    }
}
