<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\LeadBundle\Model\CompanyModel;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

class UpdateCompanyTool extends AbstractTool
{
    public function __construct(
        private CompanyModel $companyModel,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'update_company';
    }

    public function getDescription(): string
    {
        return 'Met a jour les champs d une entreprise existante. Utiliser list_entities (type company non couvert, '
            .'utiliser search_companies) pour recuperer l identifiant.';
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
            'company_id' => ['type' => 'integer', 'description' => 'Identifiant de l entreprise.'],
            'fields'     => [
                'type'        => 'object',
                'description' => 'Champs a modifier : alias Mautic -> nouvelle valeur.',
            ],
        ], ['company_id', 'fields']);
    }

    public function execute(array $arguments): array
    {
        $id     = (int) ($arguments['company_id'] ?? 0);
        $fields = array_map('strval', (array) ($arguments['fields'] ?? []));

        if (0 === $id) {
            return ['status' => 'error', 'error' => 'company_id est obligatoire.'];
        }

        if ([] === $fields) {
            return ['status' => 'error', 'error' => 'fields est obligatoire et ne peut pas etre vide.'];
        }

        $company = $this->companyModel->getEntity($id);

        if (null === $company) {
            return ['status' => 'error', 'error' => sprintf('Entreprise #%d introuvable.', $id)];
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'    => 'company',
                'company' => ['id' => $company->getId(), 'name' => $company->getName()],
                'fields'  => $fields,
            ]);
        }

        $this->companyModel->setFieldValues($company, $fields);
        $this->companyModel->saveEntity($company);

        return $this->ok([
            'id'   => $company->getId(),
            'name' => $company->getName(),
            'url'  => '/s/companies/view/'.$company->getId(),
        ]);
    }
}
