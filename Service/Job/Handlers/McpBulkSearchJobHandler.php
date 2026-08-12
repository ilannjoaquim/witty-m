<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Job\Handlers;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobItem;
use MauticPlugin\WittyBundle\Service\Job\JobHandlerInterface;
use MauticPlugin\WittyBundle\Service\Mcp\McpClientInterface;

/**
 * Pagine N'IMPORTE QUEL outil decouvert sur un serveur MCP (Prospeo,
 * data.gouv.fr, un futur fournisseur...) jusqu'a target_count elements ou
 * epuisement — un seul handler generique plutot qu'un par fournisseur.
 *
 * Contrairement a ApolloBulkEnrichPeopleJobHandler/QuickenrichBulkSearchJobHandler
 * (clients REST que ce plugin ecrit et controle, schema de pagination connu
 * avec certitude), un outil MCP decouvert en direct (cf. McpClientInterface::listTools())
 * a un schema defini par SON fournisseur, jamais code en dur ici : plutot que
 * de deviner le nom du parametre de pagination (page ? offset ? autre chose
 * encore selon le serveur), c'est start_bulk_mcp_search qui le fait fournir
 * explicitement par l'agent (qui, lui, voit le schema reel via listTools()) —
 * page_argument, point de depart, pas — et ce handler se contente de le faire
 * varier a chaque lot. Meme raisonnement pour items_field : la forme du JSON
 * de reponse differe d'un outil a l'autre, impossible a supposer sans se
 * tromper pour l'un des deux fournisseurs actuels.
 */
class McpBulkSearchJobHandler implements JobHandlerInterface
{
    public const TYPE = 'mcp_bulk_search';

    /**
     * @param iterable<McpClientInterface> $mcpClients
     */
    public function __construct(
        private iterable $mcpClients,
        private EntityManagerInterface $em,
    ) {
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function processChunk(WittyBackgroundJob $job): void
    {
        $params = $job->getParams();

        $namespace     = (string) ($params['namespace'] ?? '');
        $toolName      = (string) ($params['tool_name'] ?? '');
        $baseArguments = (array) ($params['arguments'] ?? []);
        $pageArgument  = (string) ($params['page_argument'] ?? '');
        $pageStep      = max(1, (int) ($params['page_step'] ?? 1));
        $targetCount   = (int) ($params['target_count'] ?? 0);
        $itemsField    = (string) ($params['items_field'] ?? '');

        $client = $this->findClient($namespace);

        if (null === $client || !$client->isConfigured()) {
            $job->setStatus(WittyBackgroundJob::STATUS_FAILED)->setErrorMessage(sprintf('Serveur MCP "%s" introuvable ou non configure.', $namespace));

            return;
        }

        $cursor    = $job->getResumeCursor() ?? ['page' => (int) ($params['page_start'] ?? 1), 'collected' => 0];
        $page      = $cursor['page'] ?? ($params['page_start'] ?? 1);
        $collected = (int) ($cursor['collected'] ?? 0);

        $arguments = $baseArguments;

        if ('' !== $pageArgument) {
            $arguments[$pageArgument] = $page;
        }

        try {
            $result = $client->callTool($toolName, $arguments);
        } catch (\Throwable $e) {
            $job->setStatus(WittyBackgroundJob::STATUS_FAILED)->setErrorMessage($e->getMessage());

            return;
        }

        if ('error' === ($result['status'] ?? null)) {
            $job->setStatus(WittyBackgroundJob::STATUS_FAILED)->setErrorMessage(is_string($result['result'] ?? null) ? $result['result'] : 'Erreur MCP non precisee.');

            return;
        }

        $decoded = $this->decode($result['result'] ?? null);

        if (null === $decoded) {
            $job->setStatus(WittyBackgroundJob::STATUS_FAILED)->setErrorMessage('Reponse MCP non structuree (JSON attendu), impossible d en extraire des elements.');

            return;
        }

        $items = is_array($decoded[$itemsField] ?? null) ? array_values($decoded[$itemsField]) : [];

        foreach ($items as $index => $item) {
            $data = is_array($item) ? $item : ['value' => $item];
            $ref  = (string) ($data['id'] ?? $data['request_id'] ?? sprintf('page%s-%d', (string) $page, $index));

            $jobItem = (new WittyBackgroundJobItem())
                ->setJob($job)
                ->setExternalRef($ref)
                ->setStatus(WittyBackgroundJobItem::STATUS_SUCCEEDED)
                ->setData($data);

            $this->em->persist($jobItem);
        }

        $found = count($items);
        $collected += $found;

        $job->setResumeCursor(['page' => (is_int($page) ? $page : (int) $page) + $pageStep, 'collected' => $collected]);
        $job->setProcessedItems($job->getProcessedItems() + $found);
        $job->setSucceededItems($job->getSucceededItems() + $found);

        if (0 === $found || $collected >= $targetCount) {
            $job->setStatus(WittyBackgroundJob::STATUS_COMPLETED);
        }
    }

    private function findClient(string $namespace): ?McpClientInterface
    {
        foreach ($this->mcpClients as $client) {
            if ($client->getNamespace() === $namespace) {
                return $client;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(mixed $result): ?array
    {
        if (is_array($result)) {
            return $result;
        }

        if (!is_string($result)) {
            return null;
        }

        $decoded = json_decode($result, true);

        return is_array($decoded) ? $decoded : null;
    }
}
