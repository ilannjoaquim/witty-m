<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Audit;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\WittyBundle\Entity\WittyAuditLog;
use MauticPlugin\WittyBundle\Entity\WittyConversation;
use MauticPlugin\WittyBundle\Service\Tool\ToolInterface;
use Psr\Log\LoggerInterface;

/**
 * Trace chaque execution d'outil dans witty_audit_log.
 *
 * Le logger Symfony reste utile au debogage mais ne repond pas a la question
 * « qui a cree cet email et sur quelle demande » : c'est le role de cette table.
 */
class AuditLogger
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserHelper $userHelper,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $output
     */
    public function record(
        ToolInterface $tool,
        array $arguments,
        array $output,
        int $durationMs,
        ?WittyConversation $conversation = null,
    ): void {
        $user = $this->userHelper->getUser();

        $entry = new WittyAuditLog();
        $entry->setTool($tool->getName())
            ->setWriteOperation($tool->isWriteOperation())
            ->setArguments($this->redact($arguments))
            ->setStatus((string) ($output['status'] ?? WittyAuditLog::STATUS_OK))
            ->setObject($tool->getObjectType(), isset($output['id']) ? (int) $output['id'] : null)
            ->setMessage($this->summarize($output))
            ->setDurationMs($durationMs);

        if ($user instanceof User && null !== $user->getId()) {
            $entry->setUser($user);
            $entry->setUserName((string) $user->getUserIdentifier());
        }

        if (null !== $conversation && null !== $conversation->getId()) {
            $entry->setConversation($conversation);
        }

        try {
            $this->entityManager->persist($entry);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            // Un journal indisponible ne doit pas faire echouer l'action de
            // l'utilisateur : on retombe sur le logger applicatif.
            $this->logger->error('Witty audit log failure', ['exception' => $e, 'tool' => $tool->getName()]);
        }
    }

    /**
     * Les arguments partent en base tels quels : on retire ce qui n'a pas a y
     * figurer et on borne les valeurs longues (corps HTML d'un email, par ex.).
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function redact(array $arguments): array
    {
        unset($arguments['confirmed']);

        foreach ($arguments as $key => $value) {
            if (is_string($value) && mb_strlen($value) > 500) {
                $arguments[$key] = mb_substr($value, 0, 500).sprintf(' […%d caracteres]', mb_strlen($value));
            }
        }

        return $arguments;
    }

    /**
     * @param array<string, mixed> $output
     */
    private function summarize(array $output): ?string
    {
        foreach (['error', 'name', 'message'] as $key) {
            if (isset($output[$key]) && is_string($output[$key])) {
                return $output[$key];
            }
        }

        return null;
    }
}
