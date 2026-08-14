<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Job\Handlers;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\ListLead;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobItem;
use MauticPlugin\WittyBundle\Service\Job\JobHandlerInterface;
use MauticPlugin\WittyBundle\Service\Quickenrich\Exception\QuickenrichException;
use MauticPlugin\WittyBundle\Service\Quickenrich\QuickenrichClient;

/**
 * Revele l'email et/ou le telephone (`quickenrich_find_employee_email`/
 * `quickenrich_find_employee_phone`, cf. leurs docblocks) pour TOUS les
 * contacts d'un segment Mautic — l'absence de ce handler etait une vraie
 * limite signalee par l'agent lui-meme : les deux outils de reveal
 * n'existaient qu'en appel unitaire, obligeant a retyper un par un pour un
 * volume de plusieurs milliers de contacts.
 *
 * Necessite un lien LinkedIn deja present sur le contact (`linkedin`, alias
 * Mautic standard, cf. FieldWriteGuard/README pour l'historique de ce champ) :
 * c'est l'identifiant le plus fiable pour QuickEnrich, et le seul exploitable
 * en masse sans requeter une Company pour chaque contact (le repli
 * company_url+first_name+last_name des outils unitaires n'est PAS repris ici,
 * volontairement — cf. plus bas). Un contact sans LinkedIn est explicitement
 * ecarte (SKIPPED), jamais silencieusement ignore.
 *
 * `linkedin` n'est PAS un champ Doctrine mappe sur Lead (seuls title/firstname/
 * lastname/company/position/email/phone/mobile/address1/address2/city/state/
 * zipcode/timezone/country le sont, cf. Lead::loadMetadata()) : impossible de le lire
 * via une simple requete DQL comme pour ces champs-la. Lu en SQL natif, en
 * lot, plutot que de charger chaque Lead entierement (LeadModel::getEntity(),
 * qui hydrate tous les groupes de champs) juste pour cette seule valeur.
 *
 * Chaque contact declenche un ou deux vrais appels HTTP QuickEnrich (email
 * et/ou telephone, jamais un seul appel groupe comme Apollo bulk_match).
 *
 * allowsMultiplePassesPerTick()=true, EXCEPTION au defaut "false" des autres
 * handlers qui touchent un fournisseur externe (Apollo/QuickEnrich-recherche/
 * MCP) : question posee en session (BATCH_SIZE=40 sur un seul lot par
 * minute plafonnait a 40-80 appels/minute, alors que le fournisseur autorise
 * 1000/minute par cle API — chiffre precis communique par l'utilisateur,
 * jamais suppose). Justifie ICI et pas ailleurs parce que ce chiffre exact
 * permet un throttle deterministe (MIN_CALL_INTERVAL_SECONDS, pause calculee
 * entre deux appels consecutifs) qui GARANTIT de ne jamais le depasser, quel
 * que soit le nombre de passages enchaines dans un meme cron — contrairement
 * a Apollo/QuickEnrich-recherche/MCP dont la limite reelle n'est pas connue
 * avec cette precision ici, un multi-passage y resterait une supposition.
 *
 * QuickEnrich est strict sur la forme de linkedin_url (constate en session) :
 * - un caractere accentue (jamais cense se retrouver dans une URL LinkedIn,
 *   mais un import/une source tierce peut en laisser passer) fait echouer la
 *   requete en HTTP 422 -> transliteres en ASCII avant tout appel
 *   (normalizeLinkedinUrl()) plutot que d'echouer pour rien sur un contact
 *   par ailleurs valide.
 * - une valeur qui n'est manifestement pas une URL (pas de http:// ni
 *   https://) est ecartee AVANT meme d'appeler QuickEnrich, meme raisonnement
 *   que l'absence de lien : ne jamais envoyer une requete vouee a l'echec.
 * - un HTTP 422 malgre tout (donnee specifiquement invalide pour CE contact,
 *   ex. profil LinkedIn inexistant) ne doit PAS faire echouer tout le job —
 *   contrairement a une vraie panne fournisseur (401/429/5xx/timeout), ou
 *   arreter le job reste correct (cf. resume_bulk_job pour reprendre
 *   proprement). Seul ce dernier cas de figure fait echouer le job entier ;
 *   un 422 est trace comme un echec de CET element et le lot continue.
 */
class QuickenrichBulkEnrichPeopleJobHandler implements JobHandlerInterface
{
    public const TYPE = 'quickenrich_bulk_enrich_people';

    private const BATCH_SIZE = 60;

    /** Codes HTTP QuickEnrich specifiques a UNE requete (donnee invalide) : n'arretent jamais tout le job. */
    private const PER_ITEM_ERROR_CODES = [400, 422];

    /**
     * 1000 requetes/minute par cle API (communique par l'utilisateur) =
     * 60 secondes / 1000 = 60ms minimum entre deux appels pour ne jamais la
     * depasser. 65ms plutot que 60 : marge volontaire (~923/minute au lieu
     * de 1000 pile), pour absorber l'imprecision entre deux passages
     * successifs (cf. processChunk(), le compteur ne persiste pas d'un appel
     * a l'autre).
     */
    private const MIN_CALL_INTERVAL_SECONDS = 0.065;

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
        // Exception justifiee, cf. docblock de classe : le debit QuickEnrich
        // (1000/minute) est precisement connu et auto-applique en interne
        // (MIN_CALL_INTERVAL_SECONDS), donc plusieurs passages enchaines
        // dans un meme cron restent surs.
        return true;
    }

    public function processChunk(WittyBackgroundJob $job): void
    {
        $params    = $job->getParams();
        $segmentId = (int) ($params['segment_id'] ?? 0);
        $reveal    = (array) ($params['reveal'] ?? ['email', 'phone']);
        $wantEmail = in_array('email', $reveal, true);
        $wantPhone = in_array('phone', $reveal, true);

        $cursor     = $job->getResumeCursor() ?? ['last_lead_id' => 0];
        $lastLeadId = (int) ($cursor['last_lead_id'] ?? 0);

        $leadIds = $this->nextLeadIds($segmentId, $lastLeadId);

        if ([] === $leadIds) {
            $job->setStatus(WittyBackgroundJob::STATUS_COMPLETED);

            return;
        }

        $leads          = $this->em->getRepository(Lead::class)->findBy(['id' => $leadIds]);
        $linkedinByLead = $this->fetchLinkedinUrls($leadIds);

        // Ne persiste PAS d'un appel a processChunk() au suivant (variable
        // locale) : chaque nouveau passage repart avec un premier appel non
        // retarde. Effet de bord accepte (cf. MIN_CALL_INTERVAL_SECONDS) :
        // la marge de securite (~923/min au lieu de 1000) l'absorbe.
        $lastCallAt = null;

        foreach ($leads as $lead) {
            $rawLinkedinUrl = trim((string) ($linkedinByLead[$lead->getId()] ?? ''));

            if ('' === $rawLinkedinUrl) {
                $this->recordItem($job, (string) $lead->getId(), WittyBackgroundJobItem::STATUS_SKIPPED, null, 'Aucun lien LinkedIn sur ce contact (obligatoire pour cet enrichissement en masse).');
                continue;
            }

            $linkedinUrl = $this->normalizeLinkedinUrl($rawLinkedinUrl);

            if (null === $linkedinUrl) {
                $this->recordItem($job, (string) $lead->getId(), WittyBackgroundJobItem::STATUS_SKIPPED, null, sprintf('Lien LinkedIn invalide (pas une URL http/https exploitable) : "%s".', $rawLinkedinUrl));
                continue;
            }

            $found      = [];
            $lastError  = null;

            foreach (array_filter([
                'email' => $wantEmail ? '/employees/search' : null,
                'phone' => $wantPhone ? '/employees/phone-search' : null,
            ]) as $target => $path) {
                $this->throttle($lastCallAt);

                try {
                    $response = $this->quickenrich->get($path, ['linkedin_url' => $linkedinUrl]);
                    $value    = trim((string) ($response['data'][$target] ?? ''));

                    if ('' !== $value) {
                        $found[$target] = $value;
                    }
                } catch (QuickenrichException $e) {
                    if (!in_array($e->getCode(), self::PER_ITEM_ERROR_CODES, true)) {
                        // Panne fournisseur reelle (pas une donnee invalide
                        // propre a ce contact) : le lot entier ne peut plus
                        // avancer de facon fiable, on arrete le job (reprenable
                        // ensuite via resume_bulk_job).
                        $job->setStatus(WittyBackgroundJob::STATUS_FAILED)->setErrorMessage($e->getMessage());

                        return;
                    }

                    // Erreur specifique a CE contact (422 typiquement) : ne
                    // compromet pas les autres cibles (email/phone) ni les
                    // autres contacts du lot, juste tracee pour celui-ci.
                    $lastError = $e->getMessage();
                }
            }

            if ([] === $found) {
                $this->recordItem($job, (string) $lead->getId(), WittyBackgroundJobItem::STATUS_FAILED, null, $lastError ?? 'Aucune donnee trouvee par QuickEnrich pour ce lien LinkedIn.');
                continue;
            }

            $this->recordItem($job, (string) $lead->getId(), WittyBackgroundJobItem::STATUS_SUCCEEDED, $found + ['linkedin_url' => $linkedinUrl]);
        }

        $job->setResumeCursor(['last_lead_id' => max($leadIds)]);
        $job->setProcessedItems($job->getProcessedItems() + count($leadIds));

        if (count($leadIds) < self::BATCH_SIZE) {
            $job->setStatus(WittyBackgroundJob::STATUS_COMPLETED);
        }
    }

    /**
     * Garantit au moins MIN_CALL_INTERVAL_SECONDS depuis le dernier appel
     * QuickEnrich avant de rendre la main — jamais suppose respecte par la
     * seule taille du lot, mesure et attendu explicitement a chaque appel.
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

    /**
     * Translitere les caracteres accentues en ASCII (QuickEnrich renvoie une
     * 422 sinon, constate en session) puis verifie qu'il s'agit bien d'une
     * URL http(s) exploitable. Renvoie null si la valeur n'est manifestement
     * pas une URL LinkedIn valide : jamais envoyee a QuickEnrich dans ce cas.
     */
    private function normalizeLinkedinUrl(string $rawUrl): ?string
    {
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $rawUrl);
        $url            = false !== $transliterated ? trim($transliterated) : $rawUrl;

        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            return null;
        }

        return $url;
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
     * `linkedin` n'etant pas mappe par Doctrine (cf. docblock de classe), lu
     * en SQL natif plutot que via une hydratation complete par Lead.
     *
     * @param int[] $leadIds
     *
     * @return array<int, string>
     */
    private function fetchLinkedinUrls(array $leadIds): array
    {
        if ([] === $leadIds) {
            return [];
        }

        // Table reelle (avec prefixe eventuel) obtenue via les metadonnees
        // Doctrine plutot qu'une constante supposee definie : c'est deja ce
        // que Doctrine utilise lui-meme pour mapper Lead, jamais desynchronise.
        $table      = $this->em->getClassMetadata(Lead::class)->getTableName();
        $connection = $this->em->getConnection();
        $rows       = $connection->executeQuery(
            "SELECT id, linkedin FROM {$table} WHERE id IN (?)",
            [$leadIds],
            [ArrayParameterType::INTEGER],
        )->fetchAllAssociative();

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = (string) ($row['linkedin'] ?? '');
        }

        return $byId;
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
