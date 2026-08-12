<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Job\Handlers;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobItem;
use MauticPlugin\WittyBundle\Service\Job\JobHandlerInterface;
use MauticPlugin\WittyBundle\Service\Quickenrich\Exception\QuickenrichException;
use MauticPlugin\WittyBundle\Service\Quickenrich\QuickenrichClient;

/**
 * Pagine `POST /employees/contact-finder` (QuickEnrich) jusqu'a target_count
 * resultats ou epuisement — declenche par start_quickenrich_bulk_search quand
 * le volume demande depasse ce qu'un quickenrich_search_contacts synchrone
 * peut raisonnablement couvrir en un tour de chat.
 *
 * params.body est le corps de requete DEJA valide par le start tool
 * (dimensions include/exclude, has_email/has_phone...), identique a ce que
 * QuickenrichSearchContactsTool construit — seuls page/per_page sont
 * geres ici, pilotes par le curseur. Resultats stockes tels quels (jamais
 * d'email/telephone en clair a ce stade, cf. QuickenrichSearchContactsTool) :
 * aucun trimming necessaire, la reponse QuickEnrich est deja compacte.
 */
class QuickenrichBulkSearchJobHandler implements JobHandlerInterface
{
    public const TYPE = 'quickenrich_bulk_search_contacts';

    private const PER_PAGE = 100;

    public function __construct(
        private QuickenrichClient $quickenrich,
        private EntityManagerInterface $em,
    ) {
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function processChunk(WittyBackgroundJob $job): void
    {
        $params      = $job->getParams();
        $body        = (array) ($params['body'] ?? []);
        $targetCount = (int) ($params['target_count'] ?? 0);

        $cursor    = $job->getResumeCursor() ?? ['page' => 1, 'collected' => 0];
        $page      = (int) ($cursor['page'] ?? 1);
        $collected = (int) ($cursor['collected'] ?? 0);

        $body['page']     = $page;
        $body['per_page'] = self::PER_PAGE;

        try {
            $response = $this->quickenrich->post('/employees/contact-finder', $body);
        } catch (QuickenrichException $e) {
            $job->setStatus(WittyBackgroundJob::STATUS_FAILED)->setErrorMessage($e->getMessage());

            return;
        }

        $contacts = array_values((array) ($response['data'] ?? []));

        foreach ($contacts as $index => $contact) {
            if (!is_array($contact)) {
                continue;
            }

            $item = (new WittyBackgroundJobItem())
                ->setJob($job)
                ->setExternalRef(sprintf('page%d-%d', $page, $index))
                ->setStatus(WittyBackgroundJobItem::STATUS_SUCCEEDED)
                ->setData($contact);

            $this->em->persist($item);
        }

        $found = count($contacts);
        $collected += $found;

        $job->setResumeCursor(['page' => $page + 1, 'collected' => $collected]);
        $job->setProcessedItems($job->getProcessedItems() + $found);
        $job->setSucceededItems($job->getSucceededItems() + $found);

        if (0 === $found || $found < self::PER_PAGE || $collected >= $targetCount) {
            $job->setStatus(WittyBackgroundJob::STATUS_COMPLETED);
        }
    }
}
