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
 *
 * allowsMultiplePassesPerTick()=true, meme exception justifiee et meme
 * raisonnement que QuickenrichBulkEnrichPeopleJobHandler (a lire pour le
 * detail) : question posee en session — la premiere version restait a un
 * seul appel Contact Finder par minute (1/120e du debit autorise, encore
 * plus sous-exploite que ne l'etait l'enrichissement avant son propre fix).
 * Le debit Contact Finder (120 requetes/minute par cle API, communique par
 * l'utilisateur) est ici aussi precisement connu et auto-applique en interne
 * (MIN_CALL_INTERVAL_SECONDS).
 *
 * Difference avec QuickenrichBulkEnrichPeopleJobHandler : ce handler ne fait
 * qu'UN SEUL appel par processChunk() (jamais plusieurs dans la meme boucle),
 * le throttle doit donc survivre D'UN PASSAGE A L'AUTRE — impossible avec une
 * simple variable locale. `last_call_at` rejoint page/collected dans
 * resumeCursor pour cette seule raison : ce n'est pas un etat de reprise au
 * sens propre (la position dans la pagination ne depend pas de lui), juste
 * le vehicule deja existant le plus simple pour faire persister ce timestamp
 * entre deux appels a processChunk().
 */
class QuickenrichBulkSearchJobHandler implements JobHandlerInterface
{
    public const TYPE = 'quickenrich_bulk_search_contacts';

    private const PER_PAGE = 100;

    /**
     * 120 requetes/minute par cle API pour Contact Finder (communique par
     * l'utilisateur) = 60s / 120 = 500ms minimum entre deux appels. 550ms
     * plutot que 500 : marge volontaire (~109/minute au lieu de 120 pile),
     * meme raisonnement que QuickenrichBulkEnrichPeopleJobHandler::MIN_CALL_INTERVAL_SECONDS.
     */
    private const MIN_CALL_INTERVAL_SECONDS = 0.55;

    public function __construct(
        private QuickenrichClient $quickenrich,
        private EntityManagerInterface $em,
    ) {
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function allowsMultiplePassesPerTick(): bool
    {
        // Exception justifiee, cf. docblock de classe : le debit Contact
        // Finder (120/minute) est precisement connu et auto-applique en
        // interne (MIN_CALL_INTERVAL_SECONDS), donc plusieurs passages
        // enchaines dans un meme cron restent surs.
        return true;
    }

    public function processChunk(WittyBackgroundJob $job): void
    {
        $params      = $job->getParams();
        $body        = (array) ($params['body'] ?? []);
        $targetCount = (int) ($params['target_count'] ?? 0);

        $cursor     = $job->getResumeCursor() ?? ['page' => 1, 'collected' => 0];
        $page       = (int) ($cursor['page'] ?? 1);
        $collected  = (int) ($cursor['collected'] ?? 0);
        $lastCallAt = isset($cursor['last_call_at']) ? (float) $cursor['last_call_at'] : null;

        $this->throttle($lastCallAt);

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

        $job->setResumeCursor(['page' => $page + 1, 'collected' => $collected, 'last_call_at' => $lastCallAt]);
        $job->setProcessedItems($job->getProcessedItems() + $found);
        $job->setSucceededItems($job->getSucceededItems() + $found);

        if (0 === $found || $found < self::PER_PAGE || $collected >= $targetCount) {
            $job->setStatus(WittyBackgroundJob::STATUS_COMPLETED);
        }
    }

    /**
     * Garantit au moins MIN_CALL_INTERVAL_SECONDS depuis le dernier appel
     * Contact Finder avant de rendre la main — y compris D'UN PASSAGE DE
     * processChunk() A L'AUTRE (cf. docblock de classe), jamais suppose
     * couvert par la seule latence reseau ou le temps ecoule entre deux
     * passages.
     */
    private function throttle(?float &$lastCallAt): void
    {
        if (null !== $lastCallAt) {
            $elapsed = microtime(true) - $lastCallAt;

            if ($elapsed < self::MIN_CALL_INTERVAL_SECONDS) {
                usleep((int) ((self::MIN_CALL_INTERVAL_SECONDS - $elapsed) * 1_000_000));
            }
        }

        $lastCallAt = microtime(true);
    }
}
