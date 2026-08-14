<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Job\Handlers;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\Company;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobItem;
use MauticPlugin\WittyBundle\Service\Apollo\ApolloClient;
use MauticPlugin\WittyBundle\Service\Apollo\ApolloResponseTrimmer;
use MauticPlugin\WittyBundle\Service\Apollo\Exception\ApolloException;
use MauticPlugin\WittyBundle\Service\Job\JobHandlerInterface;

/**
 * Enrichit, 10 par 10 (plafond Apollo Bulk Organization Enrichment,
 * `POST /organizations/bulk_enrich`), une liste d'entreprises Mautic DEJA
 * CONNUES (params.company_ids, fournis a la creation du job) — contrairement
 * aux contacts, Mautic n a pas de notion de "segment d entreprises" : la
 * liste d id est le seul point d entree naturel, pas une requete a construire
 * nous-memes comme ApolloBulkEnrichPeopleJobHandler::nextLeadIds().
 *
 * Les identifiants envoyes a Apollo (name/website) sont derives directement
 * des champs DEJA connus de chaque Company Mautic (Company::getName()/
 * getWebsite()), jamais redemandes a l agent : il a deja fourni le
 * company_id, pas la peine de lui faire retaper le nom/site qu on peut lire
 * nous-memes.
 *
 * Meme principe de correlation positionnelle et d echec explicite qu
 * ApolloBulkEnrichPeopleJobHandler (la reponse Apollo ne porte aucune cle de
 * correlation).
 */
class ApolloBulkEnrichCompaniesJobHandler implements JobHandlerInterface
{
    public const TYPE = 'apollo_bulk_enrich_companies';

    private const BATCH_SIZE = 10;

    public function __construct(
        private ApolloClient $apollo,
        private EntityManagerInterface $em,
    ) {
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function allowsMultiplePassesPerTick(): bool
    {
        // Appelle Apollo (API externe) a chaque passage : rester a un lot
        // par minute plutot que de risquer sa limite de debit.
        return false;
    }

    public function processChunk(WittyBackgroundJob $job): void
    {
        $params     = $job->getParams();
        $companyIds = array_values(array_map('intval', (array) ($params['company_ids'] ?? [])));

        $cursor = $job->getResumeCursor() ?? ['offset' => 0];
        $offset = (int) ($cursor['offset'] ?? 0);

        $batchIds = array_slice($companyIds, $offset, self::BATCH_SIZE);

        if ([] === $batchIds) {
            $job->setStatus(WittyBackgroundJob::STATUS_COMPLETED);

            return;
        }

        $companies = $this->em->getRepository(Company::class)->findBy(['id' => $batchIds]);
        $byId      = [];

        foreach ($companies as $company) {
            $byId[$company->getId()] = $company;
        }

        $details   = [];
        $matchedTo = [];
        $skipped   = [];

        foreach ($batchIds as $companyId) {
            $company = $byId[$companyId] ?? null;

            if (!$company instanceof Company) {
                $skipped[$companyId] = 'Entreprise introuvable (supprimee depuis ?).';
                continue;
            }

            $fields = array_filter([
                'name'    => trim((string) $company->getName()),
                'website' => trim((string) $company->getWebsite()),
            ], static fn (string $value): bool => '' !== $value);

            if ([] === $fields) {
                $skipped[$companyId] = 'Aucun identifiant exploitable (nom/site vide).';
                continue;
            }

            $details[]                = $fields;
            $matchedTo[$companyId]    = $company;
        }

        $matches = [];

        if ([] !== $details) {
            try {
                $response = $this->apollo->post('/organizations/bulk_enrich', ['details' => $details]);
            } catch (ApolloException $e) {
                $job->setStatus(WittyBackgroundJob::STATUS_FAILED)->setErrorMessage($e->getMessage());

                return;
            }

            $matches = array_values((array) ($response['organizations'] ?? []));

            if (count($matches) !== count($matchedTo)) {
                $job->setStatus(WittyBackgroundJob::STATUS_FAILED)->setErrorMessage(sprintf(
                    'Reponse Apollo inattendue : %d resultat(s) pour %d entreprise(s) envoyees.',
                    count($matches),
                    count($matchedTo),
                ));

                return;
            }
        }

        foreach ($skipped as $companyId => $reason) {
            $this->recordItem($job, (string) $companyId, WittyBackgroundJobItem::STATUS_SKIPPED, null, $reason);
        }

        foreach (array_values($matchedTo) as $index => $company) {
            $match = is_array($matches[$index] ?? null) ? $matches[$index] : [];

            if ([] === $match) {
                $this->recordItem($job, (string) $company->getId(), WittyBackgroundJobItem::STATUS_FAILED, null, 'Aucune correspondance Apollo.');
            } else {
                $this->recordItem($job, (string) $company->getId(), WittyBackgroundJobItem::STATUS_SUCCEEDED, ApolloResponseTrimmer::trimCompany($match));
            }
        }

        $job->setResumeCursor(['offset' => $offset + count($batchIds)]);
        $job->setProcessedItems($job->getProcessedItems() + count($batchIds));

        if (count($batchIds) < self::BATCH_SIZE) {
            $job->setStatus(WittyBackgroundJob::STATUS_COMPLETED);
        }
    }

    private function recordItem(WittyBackgroundJob $job, string $externalRef, string $status, ?array $data, ?string $error = null): void
    {
        $item = (new WittyBackgroundJobItem())
            ->setJob($job)
            ->setExternalRef($externalRef)
            ->setStatus($status)
            ->setData($data)
            ->setErrorMessage($error);

        $this->em->persist($item);

        if (WittyBackgroundJobItem::STATUS_SUCCEEDED === $status) {
            $job->setSucceededItems($job->getSucceededItems() + 1);
        } else {
            $job->setFailedItems($job->getFailedItems() + 1);
        }
    }
}
