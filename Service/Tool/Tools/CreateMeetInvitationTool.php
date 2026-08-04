<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\WittyBundle\Service\PlugNmeet\Exception\PlugNmeetException;
use MauticPlugin\WittyBundle\Service\PlugNmeet\MeetInvitationCreator;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Meme logique que l'action de campagne/formulaire homonyme
 * (MeetInvitationCreator), pour une generation ponctuelle a la demande depuis
 * le chat plutot que declenchee automatiquement par un parcours.
 */
class CreateMeetInvitationTool extends AbstractTool
{
    public function __construct(
        private MeetInvitationCreator $creator,
        private LeadModel $leadModel,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'create_meet_invitation';
    }

    public function getDescription(): string
    {
        return 'Genere le lien de connexion personnel d un contact vers plugNmeet, et trace l invitation '
            .'pour le suivi de presence (witty:meet:reconcile-attendance une fois la salle terminee). '
            .'Avec room_id : rejoint une salle existante active (webinaire, champ webinaire_invitation_link). '
            .'Sans room_id : cree une nouvelle salle dediee a ce contact (rendez-vous 1-a-1, champ meeting_invitation_link).';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return 'lead:leads:editown';
    }

    public function getObjectType(): ?string
    {
        return 'contact';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'contact_id'    => ['type' => 'integer', 'description' => 'Identifiant du contact.'],
            'contact_email' => ['type' => 'string', 'description' => 'Alternative a contact_id.'],
            'room_id'       => ['type' => 'string', 'description' => 'Salle existante et active. Omis : une nouvelle salle est creee pour ce contact (rendez-vous 1-a-1).'],
        ], []);
    }

    public function execute(array $arguments): array
    {
        $roomId = trim((string) ($arguments['room_id'] ?? ''));

        $lead = $this->resolveContact($arguments);

        if (!$lead instanceof Lead) {
            return ['status' => 'error', 'error' => 'Contact introuvable : fournis contact_id ou contact_email.'];
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'    => 'meet_invitation',
                'contact' => ['id' => $lead->getId(), 'email' => $lead->getEmail()],
                'room_id' => '' !== $roomId ? $roomId : '(nouvelle salle dediee)',
            ]);
        }

        try {
            $invitation = '' !== $roomId
                ? $this->creator->createForLead($lead, $roomId)
                : $this->creator->createNewRoomForLead($lead);
        } catch (PlugNmeetException $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }

        return $this->ok([
            'contact_id'     => $lead->getId(),
            'room_id'        => $invitation->getRoomId(),
            'invitation_id'  => $invitation->getId(),
            'url'            => '/s/contacts/view/'.$lead->getId(),
        ]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function resolveContact(array $arguments): ?Lead
    {
        if (!empty($arguments['contact_id'])) {
            return $this->leadModel->getEntity((int) $arguments['contact_id']);
        }

        $email = trim((string) ($arguments['contact_email'] ?? ''));

        if ('' === $email) {
            return null;
        }

        $matches = $this->leadModel->getRepository()->findBy(['email' => $email], null, 1);

        return $matches[0] ?? null;
    }
}
