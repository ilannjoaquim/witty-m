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
 * et/ou telephone, jamais un seul appel groupe comme Apollo bulk_match) :
 * allowsMultiplePassesPerTick()=false comme les trois autres handlers qui
 * touchent un fournisseur externe. Debit fournisseur tres genereux (1000
 * requetes/minute par cle API, communique par l'utilisateur) : BATCH_SIZE
 * reste neanmoins modere pour ne jamais risquer de depasser le budget de
 * temps d'un passage de cron (chaque appel est une vraie latence reseau, pas
 * un cout marginal negligeable).
 */
class QuickenrichBulkEnrichPeopleJobHandler implements JobHandlerInterface
{
    public const TYPE = 'quickenrich_bulk_enrich_people';

    private const BATCH_SIZE = 40;

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
        // Appelle QuickEnrich (API externe) a chaque contact : rester a un
        // lot par minute plutot que de risquer sa limite de debit.
        return false;
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

        foreach ($leads as $lead) {
            $linkedinUrl = trim((string) ($linkedinByLead[$lead->getId()] ?? ''));

            if ('' === $linkedinUrl) {
                $this->recordItem($job, (string) $lead->getId(), WittyBackgroundJobItem::STATUS_SKIPPED, null, 'Aucun lien LinkedIn sur ce contact (obligatoire pour cet enrichissement en masse).');
                continue;
            }

            $found = [];

            try {
                if ($wantEmail) {
                    $response = $this->quickenrich->get('/employees/search', ['linkedin_url' => $linkedinUrl]);
                    $email    = trim((string) ($response['data']['email'] ?? ''));

                    if ('' !== $email) {
                        $found['email'] = $email;
                    }
                }

                if ($wantPhone) {
                    $response = $this->quickenrich->get('/employees/phone-search', ['linkedin_url' => $linkedinUrl]);
                    $phone    = trim((string) ($response['data']['phone'] ?? ''));

                    if ('' !== $phone) {
                        $found['phone'] = $phone;
                    }
                }
            } catch (QuickenrichException $e) {
                $job->setStatus(WittyBackgroundJob::STATUS_FAILED)->setErrorMessage($e->getMessage());

                return;
            }

            if ([] === $found) {
                $this->recordItem($job, (string) $lead->getId(), WittyBackgroundJobItem::STATUS_FAILED, null, 'Aucune donnee trouvee par QuickEnrich pour ce lien LinkedIn.');
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
