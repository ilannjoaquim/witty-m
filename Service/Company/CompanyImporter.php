<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Company;

use Mautic\LeadBundle\Entity\Company;
use Mautic\LeadBundle\Model\CompanyModel;

/**
 * Met a jour UNE entreprise dont l'id Mautic est DEJA connu — jamais de
 * creation, jamais de recherche par nom (contrairement a un contact,
 * l'entreprise n'a pas de champ d'identite fiable equivalent a l'email ;
 * create_company lui-meme ne fait d'ailleurs aucun dedoublonnage). Reserve au
 * cas d'un job d'enrichissement sur une liste d'entreprises deja existantes
 * (cf. ApolloBulkEnrichCompaniesJobHandler, qui stocke l'id de l'entreprise
 * comme `external_ref`) : un enrichissement met a jour une entreprise qui
 * existe deja, par definition.
 */
class CompanyImporter
{
    public function __construct(private CompanyModel $companyModel)
    {
    }

    /**
     * @param array<string, string> $fields
     */
    public function updateById(int $companyId, array $fields): ?Company
    {
        $company = $this->companyModel->getEntity($companyId);

        if (!$company instanceof Company) {
            return null;
        }

        $this->companyModel->setFieldValues($company, $fields);
        $this->companyModel->saveEntity($company);

        return $company;
    }
}
