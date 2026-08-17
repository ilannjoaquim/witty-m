<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\LeadBundle\Entity\Company;
use Mautic\LeadBundle\Model\CompanyModel;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

/**
 * Suppression definitive d'une entreprise.
 *
 * Meme raisonnement que DeleteContactTool : absente de delete_entity/
 * EntityCatalog (SearchCompaniesTool documente deja pourquoi Company n a pas
 * le meme moule que les entites du catalogue). Reproduit exactement
 * CompanyController::deleteAction() du coeur Mautic : contrairement aux
 * contacts, la suppression d'entreprise n a PAS de notion own/other cote
 * coeur -- seule 'lead:leads:deleteother' est verifiee, quel que soit le
 * proprietaire.
 */
class DeleteCompanyTool extends AbstractTool
{
    public function __construct(
        private CompanyModel $companyModel,
        private CorePermissions $security,
    ) {
    }

    public function getName(): string
    {
        return 'delete_company';
    }

    public function getDescription(): string
    {
        return 'Supprime definitivement une entreprise. Irreversible : les contacts qui lui etaient '
            .'rattaches restent, seul le lien avec l entreprise et sa propre fiche disparaissent. '
            .'Demande toujours l accord explicite de l utilisateur avant d appeler cet outil avec confirmed=true.';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return 'lead:leads:deleteother';
    }

    public function getObjectType(): ?string
    {
        return 'company';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'company_id' => ['type' => 'integer', 'description' => 'Identifiant de l entreprise a supprimer.'],
        ], ['company_id']);
    }

    public function execute(array $arguments): array
    {
        $companyId = (int) ($arguments['company_id'] ?? 0);
        $company   = $companyId > 0 ? $this->companyModel->getEntity($companyId) : null;

        if (!$company instanceof Company) {
            return ['status' => 'error', 'error' => sprintf('Entreprise #%d introuvable.', $companyId)];
        }

        // Meme verification que CompanyController::deleteAction() du coeur :
        // pas de distinction own/other pour les entreprises, seule
        // 'deleteother' est verifiee.
        if (!$this->security->isGranted('lead:leads:deleteother')) {
            return ['status' => 'denied', 'error' => sprintf('Permission de suppression refusee sur l entreprise #%d.', $companyId)];
        }

        if ($this->companyModel->isLocked($company)) {
            return ['status' => 'error', 'error' => sprintf('Entreprise #%d verrouillee (en cours de modification ailleurs), reessaie plus tard.', $companyId)];
        }

        $label = $company->getName();

        if (true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'          => 'company',
                'id'            => $companyId,
                'nom'           => $label,
                'irreversible'  => true,
                'avertissement' => 'Cette suppression est definitive. Les contacts rattaches ne sont pas supprimes, seul le lien avec l entreprise disparait.',
            ]);
        }

        $this->companyModel->deleteEntity($company);

        return $this->ok([
            'id'      => $companyId,
            'name'    => $label,
            'deleted' => true,
        ]);
    }
}
