<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Job\Handlers;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\ListLead;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobItem;
use MauticPlugin\WittyBundle\Service\Apollo\ApolloClient;
use MauticPlugin\WittyBundle\Service\Apollo\ApolloResponseTrimmer;
use MauticPlugin\WittyBundle\Service\Apollo\Exception\ApolloException;
use MauticPlugin\WittyBundle\Service\Job\JobHandlerInterface;

/**
 * Enrichit, 10 par 10 (plafond Apollo Bulk People Enrichment,
 * `POST /people/bulk_match`), tous les contacts d'un segment Mautic —
 * declenche par start_apollo_bulk_enrich_people quand le volume ne tient pas
 * dans un enrich_person/bulk_enrich_people synchrone (cf. docblock de
 * JobHandlerInterface).
 *
 * Curseur = dernier lead_id traite (params.segment_id ne change jamais,
 * resumeCursor.last_lead_id avance a chaque lot) : parcourt
 * lead_lists_leads par id croissant plutot que par offset/limite classique,
 * pour rester correct meme si la composition du segment change en cours de
 * route (un contact ajoute avec un id superieur au curseur sera vu au prochain
 * lot, un contact deja traite ne peut jamais revenir en arriere).
 *
 * Une erreur Apollo sur un lot fait echouer tout le job plutot que de
 * l'ignorer silencieusement (cf. JobHandlerInterface::processChunk()) : mieux
 * vaut un job explicitement en echec, relancable, qu'un resultat partiel
 * presente comme complet.
 */
class ApolloBulkEnrichPeopleJobHandler implements JobHandlerInterface
{
    public const TYPE = 'apollo_bulk_enrich_people';

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
        $params      = $job->getParams();
        $segmentId   = (int) ($params['segment_id'] ?? 0);
        $revealEmail = true === ($params['reveal_personal_emails'] ?? false);

        $cursor     = $job->getResumeCursor() ?? ['last_lead_id' => 0];
        $lastLeadId = (int) ($cursor['last_lead_id'] ?? 0);

        $leadIds = $this->nextLeadIds($segmentId, $lastLeadId);

        if ([] === $leadIds) {
            $job->setStatus(WittyBackgroundJob::STATUS_COMPLETED);

            return;
        }

        $leads = $this->loadLeadsInOrder($leadIds);

        // Details envoyes a Apollo (un par lead avec au moins un identifiant
        // exploitable) et la liste des leads correspondants, dans le MEME
        // ordre : c'est ce qui permet de rattacher chaque match Apollo (positionnel,
        // pas de cle de correlation dans la reponse) au bon contact Mautic.
        // Rien n'est persiste avant d'etre sur de tout le lot : en cas
        // d'echec Apollo en cours de route, le job repart du meme
        // resumeCursor sans doublon d'item deja enregistre pour ce lot.
        $details   = [];
        $matchedTo = [];
        $skipped   = [];

        foreach ($leads as $lead) {
            $fields = array_filter([
                'first_name'        => trim((string) $lead->getFirstname()),
                'last_name'         => trim((string) $lead->getLastname()),
                'email'             => trim((string) $lead->getEmail()),
                'organization_name' => trim((string) $lead->getCompany()),
            ], static fn (string $value): bool => '' !== $value);

            if ([] === $fields) {
                $skipped[] = $lead;
                continue;
            }

            $details[]   = $fields;
            $matchedTo[] = $lead;
        }

        $matches = [];

        if ([] !== $details) {
            $query = $revealEmail ? ['reveal_personal_emails' => 'true'] : [];

            try {
                $response = $this->apollo->post('/people/bulk_match', ['details' => $details], $query);
            } catch (ApolloException $e) {
                $job->setStatus(WittyBackgroundJob::STATUS_FAILED)->setErrorMessage($e->getMessage());

                return;
            }

            $matches = array_values((array) ($response['matches'] ?? []));

            if (count($matches) !== count($matchedTo)) {
                $job->setStatus(WittyBackgroundJob::STATUS_FAILED)->setErrorMessage(sprintf(
                    'Reponse Apollo inattendue : %d resultat(s) pour %d profil(s) envoyes.',
                    count($matches),
                    count($matchedTo),
                ));

                return;
            }
        }

        foreach ($skipped as $lead) {
            $this->recordItem($job, (string) $lead->getId(), WittyBackgroundJobItem::STATUS_SKIPPED, null, 'Aucun identifiant exploitable (nom/email/entreprise vide).');
        }

        foreach ($matchedTo as $index => $lead) {
            $match = is_array($matches[$index] ?? null) ? $matches[$index] : [];

            if ([] === $match) {
                $this->recordItem($job, (string) $lead->getId(), WittyBackgroundJobItem::STATUS_FAILED, null, 'Aucune correspondance Apollo.');
            } else {
                $this->recordItem($job, (string) $lead->getId(), WittyBackgroundJobItem::STATUS_SUCCEEDED, ApolloResponseTrimmer::trimPerson($match));
            }
        }

        $job->setResumeCursor(['last_lead_id' => max($leadIds)]);
        $job->setProcessedItems($job->getProcessedItems() + count($leadIds));

        if (count($leadIds) < self::BATCH_SIZE) {
            $job->setStatus(WittyBackgroundJob::STATUS_COMPLETED);
        }
    }

    /**
     * @return int[]
     */
    private function nextLeadIds(int $segmentId, int $afterLeadId): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(ll.lead) AS leadId')
            ->from(ListLead::class, 'll')
            ->where('IDENTITY(ll.list) = :segmentId')
            ->andWhere('ll.manuallyRemoved = false')
            ->andWhere('IDENTITY(ll.lead) > :afterLeadId')
            ->orderBy('leadId', 'ASC')
            ->setParameter('segmentId', $segmentId)
            ->setParameter('afterLeadId', $afterLeadId)
            ->setMaxResults(self::BATCH_SIZE)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): int => (int) $row['leadId'], $rows);
    }

    /**
     * findBy() ne garantit pas l'ordre des identifiants fournis : on
     * reconstruit l'ordre attendu a la main, indispensable pour la
     * correlation positionnelle avec la reponse Apollo plus haut.
     *
     * @param int[] $leadIds
     *
     * @return Lead[]
     */
    private function loadLeadsInOrder(array $leadIds): array
    {
        $found = $this->em->getRepository(Lead::class)->findBy(['id' => $leadIds]);
        $byId  = [];

        foreach ($found as $lead) {
            $byId[$lead->getId()] = $lead;
        }

        $ordered = [];

        foreach ($leadIds as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    /**
     * @param array<string, mixed>|null $data
     */
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
