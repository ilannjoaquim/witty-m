<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\WittyBundle\Service\Field\FieldWriteGuard;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

class UpdateContactTool extends AbstractTool
{
    public function __construct(
        private LeadModel $leadModel,
        private WittyConfig $config,
        private FieldWriteGuard $fieldWriteGuard,
    ) {
    }

    public function getName(): string
    {
        return 'update_contact';
    }

    public function getDescription(): string
    {
        return 'Met a jour les champs d un contact existant, identifie par id ou email. '
            .'fields accepte n importe quel alias de champ Mautic, y compris email pour le changer.';
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
            'contact_email' => ['type' => 'string', 'description' => 'Alternative a contact_id pour identifier le contact.'],
            'fields'        => [
                'type'        => 'object',
                'description' => 'Champs a modifier : alias Mautic -> nouvelle valeur.',
            ],
        ], ['fields']);
    }

    public function execute(array $arguments): array
    {
        $fields = array_map('strval', (array) ($arguments['fields'] ?? []));

        if ([] === $fields) {
            return ['status' => 'error', 'error' => 'fields est obligatoire et ne peut pas etre vide.'];
        }

        $prepared = $this->fieldWriteGuard->prepare($fields, 'lead');

        if ([] !== $prepared['unknown']) {
            return [
                'status' => 'error',
                'error'  => sprintf(
                    "Alias de champ inconnu : %s. Verifie l orthographe avec l outil list_fields (object: 'contact') avant de reessayer.",
                    implode(', ', $prepared['unknown']),
                ),
            ];
        }

        $fields = $prepared['fields'];

        $lead = $this->resolveContact($arguments);

        if (!$lead instanceof Lead) {
            return ['status' => 'error', 'error' => 'Contact introuvable : fournis contact_id ou contact_email.'];
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'    => 'contact',
                'contact' => ['id' => $lead->getId(), 'email' => $lead->getEmail()],
                'fields'  => $fields,
            ]);
        }

        $this->leadModel->setFieldValues($lead, $fields, false, false);
        $this->leadModel->saveEntity($lead);

        return $this->ok([
            'id'    => $lead->getId(),
            'email' => $lead->getEmail(),
            'url'   => '/s/contacts/view/'.$lead->getId(),
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
