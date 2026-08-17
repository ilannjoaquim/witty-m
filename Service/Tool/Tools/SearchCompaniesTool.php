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
        return 'Recherche des entreprises par nom (ou tout autre champ texte indexe par Mautic). '
            .'La reponse inclut total (nombre d entreprises correspondant a la requete, au-dela de cette '
            .'seule page) : pour parcourir un ensemble qui depasse 100 resultats, rappelle cet outil en '
            .'augmentant start de limit a chaque fois (start=0, puis 100, puis 200...) jusqu a avoir couvert total.';
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
            'start' => ['type' => 'integer', 'description' => 'Decalage de pagination (nombre de resultats a sauter). Defaut 0.'],
        ], ['query']);
    }

    public function execute(array $arguments): array
    {
        // Meme piege que LeadRepository (cf. SearchContactsTool) : CompanyRepository
        // delegue aussi a CustomFieldRepositoryTrait::getEntitiesWithCustomFields(),
        // pas un Paginator -- withTotalCount=true est la seule facon d obtenir un
        // vrai total independant de la page courante.
        $response = $this->companyModel->getEntities([
            'start'          => max(0, (int) ($arguments['start'] ?? 0)),
            'limit'          => max(1, min(100, (int) ($arguments['limit'] ?? 20))),
            'filter'         => ['string' => (string) ($arguments['query'] ?? '')],
            'withTotalCount' => true,
        ]);

        $total     = (int) ($response['count'] ?? 0);
        $companies = $response['results'] ?? [];

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

        return $this->ok([
            'count'     => count($items),
            'total'     => $total,
            'start'     => max(0, (int) ($arguments['start'] ?? 0)),
            'companies' => $items,
        ]);
    }
}
