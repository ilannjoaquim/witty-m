<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Job\Handlers;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Deduplicate\ContactMerger;
use Mautic\LeadBundle\Deduplicate\Exception\SameContactException;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobItem;
use MauticPlugin\WittyBundle\Service\Job\JobHandlerInterface;

/**
 * Fusionne, groupe par groupe, les doublons detectes par
 * DuplicateContactGroupFinder (params.groups, fige a la creation du job par
 * StartDeduplicateContactsTool -- ce handler ne redetecte RIEN, il ne fait
 * qu'executer une liste deja calculee).
 *
 * Contrairement aux handlers d'enrichissement/recherche (WittyBackgroundJobItem
 * documente le principe : jamais d'ecriture automatique sur un contact,
 * seulement un resultat en attente de revue), celui-ci ECRIT reellement --
 * comme ImportContactsFromJobHandler. La confirmation utilisateur a lieu UNE
 * FOIS, a l'appel de start_deduplicate_contacts (avant meme la creation du
 * job), pas item par item pendant le cron : contrairement a une donnee
 * d'enrichissement (jugement humain necessaire sur sa qualite), la fusion
 * est mecanique et deterministe une fois le champ identifiant unique choisi
 * par l'utilisateur dans Mautic lui-meme (Reglages > Champs) -- exiger une
 * validation par paire serait impraticable sur des centaines de groupes et
 * n'apporterait aucune garantie supplementaire.
 *
 * Chaque groupe [gagnant, perdant1, perdant2...] (ids[0] = le plus ancien,
 * cf. DuplicateContactGroupFinder) est fusionne via Mautic\LeadBundle\Deduplicate\ContactMerger,
 * le meme service que la fusion manuelle depuis l'UI (LeadController::mergeAction()) :
 * garde l'historique/points/tags/IP du perdant sur le gagnant plutot que de
 * les perdre, journalise dans MergeRecord (visible depuis la fiche du
 * gagnant), puis supprime le perdant.
 */
class DeduplicateContactsJobHandler implements JobHandlerInterface
{
    public const TYPE = 'deduplicate_contacts';

    private const BATCH_SIZE = 20;

    public function __construct(
        private LeadModel $leadModel,
        private ContactMerger $contactMerger,
        private EntityManagerInterface $em,
    ) {
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function allowsMultiplePassesPerTick(): bool
    {
        // Aucun appel a une API externe : uniquement des lectures/ecritures
        // Mautic locales (ContactMerger), jamais de limite de debit
        // fournisseur a respecter.
        return true;
    }

    public function processChunk(WittyBackgroundJob $job): void
    {
        $groups = (array) ($job->getParams()['groups'] ?? []);
        $cursor = $job->getResumeCursor() ?? ['offset' => 0];
        $offset = (int) ($cursor['offset'] ?? 0);

        $batch = array_slice($groups, $offset, self::BATCH_SIZE);

        if ([] === $batch) {
            $job->setStatus(WittyBackgroundJob::STATUS_COMPLETED);

            return;
        }

        $processed = 0;

        foreach ($batch as $group) {
            $ids = array_values(array_map('intval', (array) $group));

            if (count($ids) < 2) {
                continue;
            }

            $winnerId = array_shift($ids);
            $winner   = $this->leadModel->getEntity($winnerId);

            if (!$winner instanceof Lead) {
                // Deja fusionne comme perdant d'un autre groupe traite plus
                // tot dans ce meme job (chevauchement residuel malgre
                // DuplicateContactGroupFinder::mergeOverlappingGroups(), ex.
                // supprime entre-temps par un autre outil) : chaque perdant
                // prevu est trace comme ignore, pas une erreur de job.
                foreach ($ids as $loserId) {
                    $this->recordItem($job, $loserId, WittyBackgroundJobItem::STATUS_SKIPPED, null, sprintf('Contact gagnant #%d introuvable (deja fusionne/supprime ?).', $winnerId));
                    ++$processed;
                }

                continue;
            }

            foreach ($ids as $loserId) {
                $loser = $this->leadModel->getEntity($loserId);

                if (!$loser instanceof Lead) {
                    $this->recordItem($job, $loserId, WittyBackgroundJobItem::STATUS_SKIPPED, null, 'Deja fusionne/supprime.');
                    ++$processed;
                    continue;
                }

                try {
                    $this->contactMerger->merge($winner, $loser);
                    $this->recordItem($job, $loserId, WittyBackgroundJobItem::STATUS_SUCCEEDED, ['merged_into' => $winnerId]);
                } catch (SameContactException $e) {
                    $this->recordItem($job, $loserId, WittyBackgroundJobItem::STATUS_SKIPPED, null, 'Meme contact que le gagnant.');
                } catch (\Throwable $e) {
                    // Erreur normale pour UNE paire (ex. contrainte
                    // inattendue) : on passe au perdant suivant plutot que de
                    // faire echouer tout le job, meme principe que
                    // QuickenrichBulkEnrichPeopleJobHandler::PER_ITEM_ERROR_CODES.
                    $this->recordItem($job, $loserId, WittyBackgroundJobItem::STATUS_FAILED, null, get_class($e).': '.$e->getMessage());
                }

                ++$processed;
            }
        }

        $job->setResumeCursor(['offset' => $offset + count($batch)]);
        $job->setProcessedItems($job->getProcessedItems() + $processed);

        if (count($batch) < self::BATCH_SIZE) {
            $job->setStatus(WittyBackgroundJob::STATUS_COMPLETED);
        }
    }

    private function recordItem(WittyBackgroundJob $job, int $loserId, string $status, ?array $data, ?string $error = null): void
    {
        $item = (new WittyBackgroundJobItem())
            ->setJob($job)
            ->setExternalRef((string) $loserId)
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
