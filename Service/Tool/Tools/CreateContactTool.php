<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Creation d'un contact. Refuse le doublon plutot que de creer une deuxieme
 * fiche pour le meme email : c'est le comportement attendu d'un import propre,
 * update_contact existe pour completer une fiche existante.
 */
class CreateContactTool extends AbstractTool
{
    public function __construct(
        private LeadModel $leadModel,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'create_contact';
    }

    public function getDescription(): string
    {
        return 'Cree un contact. email est utilise comme identifiant : si un contact avec cet email existe deja, '
            .'l outil refuse et suggere update_contact. fields accepte n importe quel champ contact standard ou personnalise '
            .'(firstname, lastname, phone, company, country...), en alias tel que defini dans Mautic.';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return 'lead:leads:create';
    }

    public function getObjectType(): ?string
    {
        return 'contact';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'email' => ['type' => 'string', 'description' => 'Adresse email du contact.'],
            'fields' => [
                'type'        => 'object',
                'description' => 'Champs additionnels : alias Mautic -> valeur (ex. firstname, lastname, phone, company).',
            ],
        ], ['email']);
    }

    public function execute(array $arguments): array
    {
        $email = trim((string) ($arguments['email'] ?? ''));

        if ('' === $email || !str_contains($email, '@')) {
            return ['status' => 'error', 'error' => 'email est obligatoire et doit etre une adresse valide.'];
        }

        $existing = $this->leadModel->getRepository()->findBy(['email' => $email], null, 1);

        if ([] !== $existing) {
            return [
                'status' => 'error',
                'error'  => sprintf('Un contact existe deja avec cet email (#%d). Utilise update_contact pour le completer.', $existing[0]->getId()),
            ];
        }

        $fields = array_filter(
            array_map('strval', (array) ($arguments['fields'] ?? [])),
            static fn (string $key): bool => 'email' !== $key,
            ARRAY_FILTER_USE_KEY,
        );

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'   => 'contact',
                'email'  => $email,
                'fields' => $fields,
            ]);
        }

        $lead = new Lead();
        $this->leadModel->setFieldValues($lead, ['email' => $email] + $fields, false, false);
        $this->leadModel->saveEntity($lead);

        return $this->ok([
            'id'    => $lead->getId(),
            'email' => $lead->getEmail(),
            'url'   => '/s/contacts/view/'.$lead->getId(),
        ]);
    }
}
