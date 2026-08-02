<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool;

use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use MauticPlugin\WittyBundle\Entity\WittyAuditLog;
use MauticPlugin\WittyBundle\Entity\WittyConversation;
use MauticPlugin\WittyBundle\Service\Audit\AuditLogger;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use Psr\Log\LoggerInterface;

class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    /**
     * @param iterable<ToolInterface> $tools
     */
    public function __construct(
        iterable $tools,
        private CorePermissions $security,
        private WittyConfig $config,
        private AuditLogger $auditLogger,
        private LoggerInterface $logger,
    ) {
        foreach ($tools as $tool) {
            $this->tools[$tool->getName()] = $tool;
        }
    }

    /**
     * Definitions exposees au modele, filtrees par les permissions de
     * l'utilisateur connecte : ce que l'utilisateur ne peut pas faire a la main,
     * l'agent ne peut pas le faire non plus.
     *
     * @return array<int, array{name: string, description: string, schema: array<mixed>}>
     */
    public function getDefinitions(): array
    {
        $definitions = [];

        foreach ($this->tools as $tool) {
            if (!$this->isAllowed($tool)) {
                continue;
            }

            $definitions[] = [
                'name'        => $tool->getName(),
                'description' => $tool->getDescription(),
                'schema'      => $tool->getSchema(),
            ];
        }

        return $definitions;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public function execute(string $name, array $arguments, ?WittyConversation $conversation = null): array
    {
        $tool = $this->tools[$name] ?? null;

        if (null === $tool) {
            return ['status' => 'error', 'error' => sprintf('Outil inconnu : %s', $name)];
        }

        $startedAt = microtime(true);

        if (!$this->isAllowed($tool)) {
            $output = ['status' => WittyAuditLog::STATUS_DENIED, 'error' => 'Permission refusee pour cette action.'];
            $this->auditLogger->record($tool, $arguments, $output, $this->elapsed($startedAt), $conversation);

            return $output;
        }

        if ($tool->isWriteOperation()
            && $this->config->requiresConfirmation()
            && true !== ($arguments['confirmed'] ?? false)
        ) {
            // Le tool decide lui-meme de l'apercu a renvoyer.
            $arguments['confirmed'] = false;
        }

        try {
            $output = $tool->execute($arguments);
        } catch (\Throwable $e) {
            $this->logger->error('Witty tool failure', ['tool' => $name, 'exception' => $e]);

            $output = ['status' => 'error', 'error' => $e->getMessage()];
        }

        $this->auditLogger->record($tool, $arguments, $output, $this->elapsed($startedAt), $conversation);

        return $output;
    }

    private function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    public function requiresConfirmation(string $name): bool
    {
        return isset($this->tools[$name])
            && $this->tools[$name]->isWriteOperation()
            && $this->config->requiresConfirmation();
    }

    private function isAllowed(ToolInterface $tool): bool
    {
        $permission = $tool->getRequiredPermission();

        return null === $permission || $this->security->isGranted($permission);
    }
}
