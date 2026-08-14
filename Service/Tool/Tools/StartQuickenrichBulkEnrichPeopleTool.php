<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Entity\ListLead;
use Mautic\LeadBundle\Model\ListModel;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Service\Job\Handlers\QuickenrichBulkEnrichPeopleJobHandler;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Lance en arriere-plan la revelation email/telephone QuickEnrich
 * (quickenrich_find_employee_email/quickenrich_find_employee_phone, jusque-la
 * uniquement disponibles en appel unitaire) sur TOUS les contacts d'un
 * segment Mautic — cf. Service/Job/Handlers/QuickenrichBulkEnrichPeopleJobHandler.php.
 *
 * Necessite que les contacts du segment aient deja un lien LinkedIn (champ
 * Mautic `linkedin`) : c'est typiquement le resultat d'un
 * start_quickenrich_bulk_search puis start_contacts_import_from_job en amont
 * (avec linkedin dans le mapping). Un contact sans LinkedIn dans le segment
 * est ecarte proprement, jamais une erreur bloquante pour les autres.
 */
class StartQuickenrichBulkEnrichPeopleTool extends AbstractTool
{
    private const REVEAL_TARGETS = ['email', 'phone'];

    public function __construct(
        private ListModel $listModel,
        private EntityManagerInterface $em,
        private UserHelper $userHelper,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'start_quickenrich_bulk_enrich_people';
    }

    public function getDescription(): string
    {
        return 'Lance en arriere-plan la revelation email et/ou telephone QuickEnrich sur TOUS les contacts d un '
            .'segment Mautic (pas d equivalent synchrone en masse : quickenrich_find_employee_email/'
            .'quickenrich_find_employee_phone ne traitent qu un contact a la fois). Necessite que chaque contact ait '
            .'deja un lien LinkedIn (champ linkedin) — typiquement pose par un import precedent depuis un job de '
            .'recherche QuickEnrich (start_quickenrich_bulk_search + start_contacts_import_from_job). Un contact sans '
            .'LinkedIn est ecarte proprement (jamais une erreur bloquante). reveal choisit email, telephone, ou les '
            .'deux (defaut). Consomme des credits QuickEnrich (facture uniquement quand une donnee est effectivement '
            .'trouvee, cf. quickenrich_find_employee_phone) — previens l utilisateur du volume avant de lancer.';
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
            'segment_id' => ['type' => 'integer'],
            'reveal'     => [
                'type'        => 'array',
                'items'       => ['type' => 'string', 'enum' => self::REVEAL_TARGETS],
                'description' => 'Sous-ensemble de ["email","phone"]. Defaut : les deux.',
            ],
        ], ['segment_id']);
    }

    public function execute(array $arguments): array
    {
        if (!$this->config->isQuickenrichConfigured()) {
            return ['status' => 'error', 'error' => 'QuickEnrich n est pas configure.'];
        }

        $segmentId = (int) ($arguments['segment_id'] ?? 0);
        $segment   = $this->listModel->getEntity($segmentId);

        if (!$segment instanceof LeadList) {
            return ['status' => 'error', 'error' => sprintf('Segment #%d introuvable.', $segmentId)];
        }

        $reveal = array_values(array_intersect(
            array_map('strval', (array) ($arguments['reveal'] ?? self::REVEAL_TARGETS)),
            self::REVEAL_TARGETS,
        ));

        if ([] === $reveal) {
            $reveal = self::REVEAL_TARGETS;
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
            ->setType(QuickenrichBulkEnrichPeopleJobHandler::TYPE)
            ->setCreatedBy($this->userHelper->getUser())
            ->setLabel(sprintf('Enrichissement QuickEnrich (%s) — segment %s (%d contacts)', implode('+', $reveal), $segment->getName(), $total))
            ->setParams([
                'segment_id' => $segmentId,
                'reveal'     => $reveal,
            ])
            ->setTotalItems($total);

        $this->em->persist($job);
        $this->em->flush();

        return $this->ok([
            'job_id'      => $job->getId(),
            'total_items' => $total,
            'message'     => sprintf(
                'Job #%d lance en arriere-plan (%d contacts, plusieurs centaines par minute — debit auto-regule '
                .'sur la limite QuickEnrich, pas un simple lot par minute). Un contact sans LinkedIn sera ecarte '
                .'(visible via list_bulk_job_items, status=skipped). Utilise check_bulk_job(job_id=%d) pour suivre '
                .'la progression.',
                $job->getId(),
                $total,
                $job->getId(),
            ),
        ]);
    }
}
