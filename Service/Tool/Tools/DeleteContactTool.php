<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

/**
 * Suppression definitive d'un contact.
 *
 * Absent volontairement de delete_entity/EntityCatalog : Lead ne suit pas le
 * meme moule que les entites du catalogue (permission own/other resolue via
 * Lead::getPermissionUser() -- owner assigne, PAS getCreatedBy() -- et un
 * verrou d'edition a verifier via LeadModel::isLocked(), ni l'un ni l'autre
 * couverts par la logique generique d'EntityCatalog::isAllowed()). Reproduit
 * ici exactement la logique de LeadController::deleteAction() du coeur
 * Mautic plutot que d'etendre le catalogue avec un cas particulier de plus.
 *
 * Toujours une confirmation explicite, meme si le mode confirmation global
 * est desactive : comme delete_entity, une suppression de contact est
 * irreversible (historique, points, appartenance aux campagnes/segments,
 * tout disparait avec).
 */
class DeleteContactTool extends AbstractTool
{
    public function __construct(
        private LeadModel $leadModel,
        private CorePermissions $security,
    ) {
    }

    public function getName(): string
    {
        return 'delete_contact';
    }

    public function getDescription(): string
    {
        return 'Supprime definitivement un contact (identifie par id ou email) et tout ce qui lui est '
            .'rattache (historique, points, appartenance aux segments/campagnes). Irreversible. '
            .'Demande toujours l accord explicite de l utilisateur avant d appeler cet outil avec confirmed=true.';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return 'lead:leads:deleteown';
    }

    public function getObjectType(): ?string
    {
        return 'contact';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'contact_id'    => ['type' => 'integer', 'description' => 'Identifiant du contact a supprimer.'],
            'contact_email' => ['type' => 'string', 'description' => 'Alternative a contact_id pour identifier le contact.'],
        ], []);
    }

    public function execute(array $arguments): array
    {
        $lead = $this->resolveContact($arguments);

        if (!$lead instanceof Lead) {
            return ['status' => 'error', 'error' => 'Contact introuvable : fournis contact_id ou contact_email.'];
        }

        // Meme verification que LeadController::deleteAction() du coeur :
        // getPermissionUser() renvoie le proprietaire assigne au contact
        // (Lead::getOwner()), pas son createur -- deleteown ne suffit donc
        // pas si le contact est assigne a quelqu un d autre.
        if (!$this->security->hasEntityAccess('lead:leads:deleteown', 'lead:leads:deleteother', $lead->getPermissionUser())) {
            return ['status' => 'denied', 'error' => sprintf('Permission de suppression refusee sur le contact #%d.', $lead->getId())];
        }

        if ($this->leadModel->isLocked($lead)) {
            return ['status' => 'error', 'error' => sprintf('Contact #%d verrouille (en cours de modification ailleurs), reessaie plus tard.', $lead->getId())];
        }

        $label = $lead->getPrimaryIdentifier();

        if (true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'          => 'contact',
                'id'            => $lead->getId(),
                'email'         => $lead->getEmail(),
                'nom'           => $label,
                'irreversible'  => true,
                'avertissement' => 'Cette suppression est definitive : historique, points et appartenance aux segments/campagnes disparaissent avec.',
            ]);
        }

        $this->leadModel->deleteEntity($lead);

        return $this->ok([
            'id'      => $lead->getId(),
            'email'   => $lead->getEmail(),
            'name'    => $label,
            'deleted' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function resolveContact(array $arguments): ?Lead
    {
        if (!empty($arguments['contact_id'])) {
            $lead = $this->leadModel->getEntity((int) $arguments['contact_id']);

            return $lead instanceof Lead ? $lead : null;
        }

        $email = trim((string) ($arguments['contact_email'] ?? ''));

        if ('' === $email) {
            return null;
        }

        $matches = $this->leadModel->getRepository()->findBy(['email' => $email], null, 1);

        return $matches[0] ?? null;
    }
}
