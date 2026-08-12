<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Service\Job\Handlers\QuickenrichBulkSearchJobHandler;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Lance en arriere-plan une recherche QuickEnrich au-dela de ce qu un seul
 * appel a quickenrich_search_contacts peut couvrir (cf.
 * Service/Job/Handlers/QuickenrichBulkSearchJobHandler.php) — memes filtres
 * que quickenrich_search_contacts, mais pagine automatiquement jusqu a
 * target_count resultats au lieu d une seule page.
 *
 * Ne renvoie jamais de resultat directement : un job_id a suivre via
 * check_bulk_job puis list_bulk_job_items une fois termine.
 */
class StartQuickenrichBulkSearchTool extends AbstractTool
{
    private const OPEN_TEXT_DIMENSIONS  = ['title', 'locality', 'company_name', 'company_url', 'city', 'bio_li'];
    private const EXACT_MATCH_DIMENSIONS = ['number_of_employees', 'revenue', 'country_code', 'industry_linkedin', 'services'];
    private const MAX_TARGET_COUNT      = 50000;

    public function __construct(
        private WittyConfig $config,
        private EntityManagerInterface $em,
        private UserHelper $userHelper,
    ) {
    }

    public function getName(): string
    {
        return 'start_quickenrich_bulk_search';
    }

    public function getDescription(): string
    {
        return 'Lance en arriere-plan une recherche QuickEnrich (memes filtres que quickenrich_search_contacts) '
            .'jusqu a target_count resultats, en paginant automatiquement — a utiliser quand le volume demande '
            .'depasse ce qu un seul appel a quickenrich_search_contacts peut couvrir. Ne renvoie jamais de resultat '
            .'directement : un job_id a suivre via check_bulk_job, puis list_bulk_job_items une fois status=completed.';
    }

    public function getObjectType(): ?string
    {
        return null;
    }

    public function getSchema(): array
    {
        $properties = [];

        foreach (self::OPEN_TEXT_DIMENSIONS as $dimension) {
            $properties[$dimension] = self::filterDimensionSchema('Texte libre.');
        }

        foreach (self::EXACT_MATCH_DIMENSIONS as $dimension) {
            $properties[$dimension] = self::filterDimensionSchema('Valeur exacte issue de quickenrich_list_filter_values.');
        }

        $properties['has_email']    = ['type' => 'boolean'];
        $properties['has_phone']    = ['type' => 'boolean'];
        $properties['target_count'] = ['type' => 'integer', 'description' => 'Nombre de resultats vises, maximum '.self::MAX_TARGET_COUNT.'.'];

        return $this->schema($properties, ['target_count']);
    }

    public function execute(array $arguments): array
    {
        if (!$this->config->isQuickenrichConfigured()) {
            return ['status' => 'error', 'error' => 'QuickEnrich n est pas configure.'];
        }

        $body            = [];
        $hasActiveFilter = false;

        foreach ([...self::OPEN_TEXT_DIMENSIONS, ...self::EXACT_MATCH_DIMENSIONS] as $dimension) {
            $raw = is_array($arguments[$dimension] ?? null) ? $arguments[$dimension] : [];

            $include = array_values(array_filter(array_map('strval', (array) ($raw['include'] ?? []))));
            $exclude = array_values(array_filter(array_map('strval', (array) ($raw['exclude'] ?? []))));

            if ([] === $include && [] === $exclude) {
                continue;
            }

            $hasActiveFilter   = true;
            $body[$dimension] = ['include' => $include, 'exclude' => $exclude];
        }

        if (true === ($arguments['has_email'] ?? false)) {
            $body['has_email'] = true;
            $hasActiveFilter   = true;
        }

        if (true === ($arguments['has_phone'] ?? false)) {
            $body['has_phone'] = true;
            $hasActiveFilter   = true;
        }

        if (!$hasActiveFilter) {
            return ['status' => 'error', 'error' => 'Au moins un filtre est obligatoire (include/exclude non vide, ou has_email/has_phone=true).'];
        }

        $targetCount = max(1, min(self::MAX_TARGET_COUNT, (int) ($arguments['target_count'] ?? 0)));

        if (0 === (int) ($arguments['target_count'] ?? 0)) {
            return ['status' => 'error', 'error' => 'target_count est obligatoire.'];
        }

        $job = (new WittyBackgroundJob())
            ->setType(QuickenrichBulkSearchJobHandler::TYPE)
            ->setCreatedBy($this->userHelper->getUser())
            ->setLabel(sprintf('Recherche QuickEnrich (%d resultats vises)', $targetCount))
            ->setParams(['body' => $body, 'target_count' => $targetCount])
            ->setTotalItems($targetCount);

        $this->em->persist($job);
        $this->em->flush();

        return $this->ok([
            'job_id'  => $job->getId(),
            'message' => sprintf(
                'Job #%d lance en arriere-plan (jusqu a %d resultats, ~100 par lot, un lot par passage de cron). '
                .'Utilise check_bulk_job(job_id=%d) pour suivre la progression.',
                $job->getId(),
                $targetCount,
                $job->getId(),
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function filterDimensionSchema(string $description): array
    {
        return [
            'type'        => 'object',
            'description' => $description,
            'properties'  => [
                'include' => ['type' => 'array', 'items' => ['type' => 'string']],
                'exclude' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }
}
