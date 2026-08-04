<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\LeadBundle\Entity\Company;
use Mautic\LeadBundle\Model\CompanyModel;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

/**
 * Company n a pas le meme moule que les entites du EntityCatalog (pas de
 * isPublished, champs personnalises) : liste et recherche restent un outil
 * dedie plutot qu un passage par list_entities.
 */
class SearchCompaniesTool extends AbstractTool
{
    public function __construct(private CompanyModel $companyModel)
    {
    }

    public function getName(): string
    {
        return 'search_companies';
    }

    public function getDescription(): string
    {
        return 'Recherche des entreprises par nom (ou tout autre champ texte indexe par Mautic).';
    }

    public function getRequiredPermission(): ?string
    {
        return 'lead:leads:viewown';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'query' => ['type' => 'string', 'description' => 'Texte de recherche, ex. nom de l entreprise.'],
            'limit' => ['type' => 'integer', 'description' => 'Defaut 20, max 100.'],
        ], ['query']);
    }

    public function execute(array $arguments): array
    {
        $companies = $this->companyModel->getEntities([
            'start'  => 0,
            'limit'  => max(1, min(100, (int) ($arguments['limit'] ?? 20))),
            'filter' => ['string' => (string) ($arguments['query'] ?? '')],
        ]);

        $items = [];

        foreach ($companies as $company) {
            if (!$company instanceof Company) {
                continue;
            }

            $items[] = [
                'id'      => $company->getId(),
                'name'    => $company->getName(),
                'email'   => $company->getEmail(),
                'website' => $company->getWebsite(),
                'city'    => $company->getCity(),
                'country' => $company->getCountry(),
            ];
        }

        return $this->ok(['count' => count($items), 'companies' => $items]);
    }
}
