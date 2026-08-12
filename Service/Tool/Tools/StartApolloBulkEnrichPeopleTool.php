<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Entity\ListLead;
use Mautic\LeadBundle\Model\ListModel;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Service\Job\Handlers\ApolloBulkEnrichPeopleJobHandler;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Lance un enrichissement Apollo (people) sur TOUS les contacts d'un segment,
 * en arriere-plan (cf. Service/Job/Handlers/ApolloBulkEnrichPeopleJobHandler.php) —
 * a utiliser quand enrich_person/bulk_enrich_people (synchrones) ne suffisent
 * pas : un segment de plusieurs centaines/milliers de contacts represente des
 * dizaines/centaines d'appels Apollo, largement au-dela de ce qu'un tour de
 * chat peut absorber d'un coup.
 *
 * Ne renvoie jamais de resultat d'enrichissement directement : uniquement un
 * job_id a suivre via check_bulk_job puis list_bulk_job_items une fois
 * termine.
 */
class StartApolloBulkEnrichPeopleTool extends AbstractTool
{
    public function __construct(
        private ListModel $listModel,
        private EntityManagerInterface $em,
        private UserHelper $userHelper,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'start_apollo_bulk_enrich_people';
    }

    public function getDescription(): string
    {
        return 'Lance en arriere-plan un enrichissement Apollo (titre, entreprise, email si reveal_personal_emails) '
            .'sur TOUS les contacts d un segment Mautic. Pour un petit nombre de contacts deja identifies, prefere '
            .'enrich_person/bulk_enrich_people (synchrones, resultat immediat) — celui-ci est reserve aux volumes qui '
            .'ne tiendraient pas dans un tour de chat. Ne renvoie jamais de resultat directement : un job_id a suivre '
            .'via check_bulk_job, puis list_bulk_job_items une fois status=completed.';
    }

    public function getObjectType(): ?string
    {
        return null;
    }

    public function getRequiredPermission(): ?string
    {
        return 'lead:leads:viewown';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'segment_id'              => ['type' => 'integer'],
            'reveal_personal_emails'  => ['type' => 'boolean', 'description' => 'Revele les emails trouves (consomme des credits Apollo). Defaut false.'],
        ], ['segment_id']);
    }

    public function execute(array $arguments): array
    {
        if (!$this->config->isApolloConfigured()) {
            return ['status' => 'error', 'error' => 'Apollo n est pas configure.'];
        }

        $segmentId = (int) ($arguments['segment_id'] ?? 0);
        $segment   = $this->listModel->getEntity($segmentId);

        if (!$segment instanceof LeadList) {
            return ['status' => 'error', 'error' => sprintf('Segment #%d introuvable.', $segmentId)];
        }

        $total = (int) $this->em->createQueryBuilder()
            ->select('COUNT(ll.lead)')
            ->from(ListLead::class, 'll')
            ->where('IDENTITY(ll.list) = :segmentId')
            ->andWhere('ll.manuallyRemoved = false')
            ->setParameter('segmentId', $segmentId)
            ->getQuery()
            ->getSingleScalarResult();

        if (0 === $total) {
            return ['status' => 'error', 'error' => 'Ce segment ne contient aucun contact.'];
        }

        $job = (new WittyBackgroundJob())
            ->setType(ApolloBulkEnrichPeopleJobHandler::TYPE)
            ->setCreatedBy($this->userHelper->getUser())
            ->setLabel(sprintf('Enrichissement Apollo (personnes) — segment %s (%d contacts)', $segment->getName(), $total))
            ->setParams([
                'segment_id'             => $segmentId,
                'reveal_personal_emails' => true === ($arguments['reveal_personal_emails'] ?? false),
            ])
            ->setTotalItems($total);

        $this->em->persist($job);
        $this->em->flush();

        return $this->ok([
            'job_id'      => $job->getId(),
            'total_items' => $total,
            'message'     => sprintf(
                'Job #%d lance en arriere-plan (%d contacts, ~10 par lot, un lot par passage de cron). '
                .'Utilise check_bulk_job(job_id=%d) pour suivre la progression.',
                $job->getId(),
                $total,
                $job->getId(),
            ),
        ]);
    }
}
