<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Service\Job\Handlers\McpBulkSearchJobHandler;
use MauticPlugin\WittyBundle\Service\Mcp\McpClientInterface;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

/**
 * Lance en arriere-plan la pagination d un outil MCP (Prospeo, data.gouv.fr)
 * au-dela de ce qu un seul appel peut couvrir — generique, pas specifique a
 * un fournisseur (cf. Service/Job/Handlers/McpBulkSearchJobHandler.php).
 *
 * A la difference des autres outils de ce plugin, celui-ci ne connait pas a
 * l avance le schema exact de l outil distant qu il pagine : c est a
 * l appelant (toi) de fournir tool_name (le nom EXACT tel qu expose, ex.
 * prospeo_search_person) et le nom du parametre de pagination de CET outil
 * precis (page_argument) d apres son schema reel — regarde la description de
 * l outil pour le deduire, ne devine jamais un nom de parametre au hasard.
 * items_field est le nom du champ de la reponse contenant le tableau de
 * resultats (ex. "results", "people", "data") — pareil, a deduire d un appel
 * synchrone prealable au meme outil si besoin.
 *
 * Ne renvoie jamais de resultat directement : un job_id a suivre via
 * check_bulk_job puis list_bulk_job_items une fois termine.
 */
class StartBulkMcpSearchTool extends AbstractTool
{
    private const MAX_TARGET_COUNT = 50000;

    /**
     * @param iterable<McpClientInterface> $mcpClients
     */
    public function __construct(
        private iterable $mcpClients,
        private EntityManagerInterface $em,
        private UserHelper $userHelper,
    ) {
    }

    public function getName(): string
    {
        return 'start_bulk_mcp_search';
    }

    public function getDescription(): string
    {
        return 'Lance en arriere-plan la pagination d un outil MCP (namespace prospeo ou datagouv) au-dela de ce '
            .'qu un seul appel peut couvrir. tool_name : nom EXACT de l outil distant (ex. prospeo_search_person, '
            .'sans le prefixe namespace_ ni avec — voir la liste d outils disponibles). arguments : criteres fixes '
            .'envoyes a chaque page. page_argument : nom du parametre de pagination du schema REEL de cet outil '
            .'(deduit de sa description, jamais devine). items_field : champ de la reponse contenant le tableau de '
            .'resultats. Ne renvoie jamais de resultat directement : un job_id a suivre via check_bulk_job.';
    }

    public function getObjectType(): ?string
    {
        return null;
    }

    public function getSchema(): array
    {
        return $this->schema([
            'namespace'      => ['type' => 'string', 'enum' => ['prospeo', 'datagouv']],
            'tool_name'      => ['type' => 'string', 'description' => 'Nom exact de l outil distant, ex. search_person.'],
            'arguments'      => ['type' => 'object', 'description' => 'Criteres fixes envoyes a chaque page.'],
            'page_argument'  => ['type' => 'string', 'description' => 'Nom du parametre de pagination du schema reel de l outil (ex. page, offset).'],
            'page_start'     => ['type' => 'integer', 'description' => 'Defaut 1.'],
            'page_step'      => ['type' => 'integer', 'description' => 'Defaut 1 (increment du parametre de pagination a chaque lot).'],
            'target_count'   => ['type' => 'integer', 'description' => 'Nombre de resultats vises, maximum '.self::MAX_TARGET_COUNT.'.'],
            'items_field'    => ['type' => 'string', 'description' => 'Champ de la reponse JSON contenant le tableau de resultats.'],
        ], ['namespace', 'tool_name', 'page_argument', 'target_count', 'items_field']);
    }

    public function execute(array $arguments): array
    {
        $namespace = (string) ($arguments['namespace'] ?? '');
        $toolName  = trim((string) ($arguments['tool_name'] ?? ''));
        $pageArg   = trim((string) ($arguments['page_argument'] ?? ''));
        $itemsField = trim((string) ($arguments['items_field'] ?? ''));
        $targetCount = (int) ($arguments['target_count'] ?? 0);

        if (!in_array($namespace, ['prospeo', 'datagouv'], true)) {
            return ['status' => 'error', 'error' => 'namespace doit valoir prospeo ou datagouv.'];
        }

        if ('' === $toolName || '' === $pageArg || '' === $itemsField) {
            return ['status' => 'error', 'error' => 'tool_name, page_argument et items_field sont obligatoires.'];
        }

        if ($targetCount < 1 || $targetCount > self::MAX_TARGET_COUNT) {
            return ['status' => 'error', 'error' => sprintf('target_count doit etre compris entre 1 et %d.', self::MAX_TARGET_COUNT)];
        }

        $client = null;

        foreach ($this->mcpClients as $candidate) {
            if ($candidate->getNamespace() === $namespace) {
                $client = $candidate;
                break;
            }
        }

        if (null === $client || !$client->isConfigured()) {
            return ['status' => 'error', 'error' => sprintf('Le serveur MCP "%s" n est pas configure.', $namespace)];
        }

        $job = (new WittyBackgroundJob())
            ->setType(McpBulkSearchJobHandler::TYPE)
            ->setCreatedBy($this->userHelper->getUser())
            ->setLabel(sprintf('Recherche %s_%s (%d resultats vises)', $namespace, $toolName, $targetCount))
            ->setParams([
                'namespace'     => $namespace,
                'tool_name'     => $toolName,
                'arguments'     => (array) ($arguments['arguments'] ?? []),
                'page_argument' => $pageArg,
                'page_start'    => (int) ($arguments['page_start'] ?? 1),
                'page_step'     => max(1, (int) ($arguments['page_step'] ?? 1)),
                'target_count'  => $targetCount,
                'items_field'   => $itemsField,
            ])
            ->setTotalItems($targetCount);

        $this->em->persist($job);
        $this->em->flush();

        return $this->ok([
            'job_id'  => $job->getId(),
            'message' => sprintf(
                'Job #%d lance en arriere-plan (jusqu a %d resultats, un lot par passage de cron). '
                .'Utilise check_bulk_job(job_id=%d) pour suivre la progression.',
                $job->getId(),
                $targetCount,
                $job->getId(),
            ),
        ]);
    }
}
