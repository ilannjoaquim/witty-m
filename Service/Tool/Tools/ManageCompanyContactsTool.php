<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\CompanyModel;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

class ManageCompanyContactsTool extends AbstractTool
{
    public function __construct(
        private CompanyModel $companyModel,
        private LeadModel $leadModel,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'manage_company_contacts';
    }

    public function getDescription(): string
    {
        return 'Rattache ou detache un contact d une entreprise. action=add ou action=remove, '
            .'contact identifie par contact_id ou contact_email, company_id l entreprise cible.';
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
        return 'company';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'action'        => ['type' => 'string', 'enum' => ['add', 'remove']],
            'company_id'    => ['type' => 'integer', 'description' => 'Identifiant de l entreprise.'],
            'contact_id'    => ['type' => 'integer', 'description' => 'Identifiant du contact.'],
            'contact_email' => ['type' => 'string', 'description' => 'Alternative a contact_id.'],
        ], ['action', 'company_id']);
    }

    public function execute(array $arguments): array
    {
        $action    = (string) ($arguments['action'] ?? '');
        $companyId = (int) ($arguments['company_id'] ?? 0);

        if (!in_array($action, ['add', 'remove'], true)) {
            return ['status' => 'error', 'error' => 'action doit valoir add ou remove.'];
        }

        $company = 0 !== $companyId ? $this->companyModel->getEntity($companyId) : null;

        if (null === $company) {
            return ['status' => 'error', 'error' => sprintf('Entreprise #%d introuvable.', $companyId)];
        }

        $lead = $this->resolveContact($arguments);

        if (null === $lead) {
            return ['status' => 'error', 'error' => 'Contact introuvable : fournis contact_id ou contact_email.'];
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'    => 'company_contact',
                'action'  => $action,
                'company' => ['id' => $company->getId(), 'name' => $company->getName()],
                'contact' => ['id' => $lead->getId(), 'email' => $lead->getEmail()],
            ]);
        }

        if ('add' === $action) {
            $this->companyModel->addLeadToCompany([$companyId], $lead);
        } else {
            $this->companyModel->removeLeadFromCompany([$companyId], $lead);
        }

        return $this->ok([
            'action'  => $action,
            'company' => ['id' => $company->getId(), 'name' => $company->getName()],
            'contact' => ['id' => $lead->getId(), 'email' => $lead->getEmail()],
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
