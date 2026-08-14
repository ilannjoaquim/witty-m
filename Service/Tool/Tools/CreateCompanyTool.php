<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\LeadBundle\Entity\Company;
use Mautic\LeadBundle\Model\CompanyModel;
use MauticPlugin\WittyBundle\Service\Field\FieldWriteGuard;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

class CreateCompanyTool extends AbstractTool
{
    public function __construct(
        private CompanyModel $companyModel,
        private WittyConfig $config,
        private FieldWriteGuard $fieldWriteGuard,
    ) {
    }

    public function getName(): string
    {
        return 'create_company';
    }

    public function getDescription(): string
    {
        return 'Cree une entreprise. fields accepte n importe quel alias de champ entreprise Mautic '
            .'(companyname, companyemail, companywebsite, companycity, companycountry, companyindustry...).';
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
        return 'company';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'name'   => ['type' => 'string', 'description' => 'Nom de l entreprise.'],
            'fields' => [
                'type'        => 'object',
                'description' => 'Champs additionnels : alias Mautic -> valeur.',
            ],
        ], ['name']);
    }

    public function execute(array $arguments): array
    {
        $name = trim((string) ($arguments['name'] ?? ''));

        if ('' === $name) {
            return ['status' => 'error', 'error' => 'name est obligatoire.'];
        }

        $fields = array_filter(
            array_map('strval', (array) ($arguments['fields'] ?? [])),
            static fn (string $key): bool => 'companyname' !== $key,
            ARRAY_FILTER_USE_KEY,
        );

        $prepared = $this->fieldWriteGuard->prepare($fields, 'company');

        if ([] !== $prepared['unknown']) {
            return [
                'status' => 'error',
                'error'  => sprintf(
                    "Alias de champ inconnu : %s. Verifie l orthographe avec l outil list_fields (object: 'company') avant de reessayer.",
                    implode(', ', $prepared['unknown']),
                ),
            ];
        }

        $fields = $prepared['fields'];

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'   => 'company',
                'name'   => $name,
                'fields' => $fields,
            ]);
        }

        $company = new Company();
        $this->companyModel->setFieldValues($company, ['companyname' => $name] + $fields);
        $this->companyModel->saveEntity($company);

        return $this->ok([
            'id'   => $company->getId(),
            'name' => $company->getName(),
            'url'  => '/s/companies/view/'.$company->getId(),
        ]);
    }
}
