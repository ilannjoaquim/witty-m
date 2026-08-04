<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Model\ListModel;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Rattache ou detache manuellement un contact d un ou plusieurs segments.
 *
 * Sans effet sur les segments a filtres automatiques dont le contact ne
 * remplit pas les criteres : Mautic le retirera au prochain
 * mautic:segments:update, comme pour tout ajout manuel.
 */
class ManageContactSegmentsTool extends AbstractTool
{
    public function __construct(
        private LeadModel $leadModel,
        private ListModel $listModel,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'manage_contact_segments';
    }

    public function getDescription(): string
    {
        return 'Ajoute ou retire un contact d un ou plusieurs segments. '
            .'action=add ou action=remove, contact identifie par contact_id ou contact_email, segment_ids liste les segments.';
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
            'action' => ['type' => 'string', 'enum' => ['add', 'remove']],
            'contact_id'    => ['type' => 'integer', 'description' => 'Identifiant du contact.'],
            'contact_email' => ['type' => 'string', 'description' => 'Alternative a contact_id.'],
            'segment_ids' => [
                'type'        => 'array',
                'items'       => ['type' => 'integer'],
                'description' => 'Identifiants des segments cibles.',
            ],
        ], ['action', 'segment_ids']);
    }

    public function execute(array $arguments): array
    {
        $action     = (string) ($arguments['action'] ?? '');
        $segmentIds = array_values(array_filter(array_map('intval', (array) ($arguments['segment_ids'] ?? []))));

        if (!in_array($action, ['add', 'remove'], true)) {
            return ['status' => 'error', 'error' => 'action doit valoir add ou remove.'];
        }

        if ([] === $segmentIds) {
            return ['status' => 'error', 'error' => 'segment_ids est obligatoire et ne peut pas etre vide.'];
        }

        $lead = $this->resolveContact($arguments);

        if (!$lead instanceof Lead) {
            return ['status' => 'error', 'error' => 'Contact introuvable : fournis contact_id ou contact_email.'];
        }

        $segments = [];

        foreach ($segmentIds as $id) {
            $segment = $this->listModel->getEntity($id);

            if (null === $segment) {
                return ['status' => 'error', 'error' => sprintf('Segment #%d introuvable.', $id)];
            }

            $segments[] = $segment;
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'     => 'contact_segments',
                'action'   => $action,
                'contact'  => ['id' => $lead->getId(), 'email' => $lead->getEmail()],
                'segments' => array_map(static fn ($s): string => (string) $s->getName(), $segments),
            ]);
        }

        // addLead()/removeLead() attendent des identifiants dans ce cas (un
        // tableau d entites y declenche un (int) cast d objet qui echoue).
        if ('add' === $action) {
            $this->listModel->addLead($lead, $segmentIds, true);
        } else {
            $this->listModel->removeLead($lead, $segmentIds, true);
        }

        return $this->ok([
            'id'       => $lead->getId(),
            'email'    => $lead->getEmail(),
            'action'   => $action,
            'segments' => $segmentIds,
            'url'      => '/s/contacts/view/'.$lead->getId(),
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
