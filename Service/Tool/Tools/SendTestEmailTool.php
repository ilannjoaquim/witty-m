<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Helper\FakeContactHelper;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Envoi d'un exemplaire de test, comme le bouton « Envoyer un exemple » de
 * l'interface. Aucun contact reel n'est touche et aucune statistique d'envoi
 * n'est enregistree.
 */
class SendTestEmailTool extends AbstractTool
{
    public function __construct(
        private EmailModel $emailModel,
        private UserHelper $userHelper,
        private FakeContactHelper $fakeContactHelper,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'send_test_email';
    }

    public function getDescription(): string
    {
        return 'Envoie un exemplaire de test d un email existant, par defaut a l adresse de l utilisateur connecte. '
            .'Ne touche aucun contact et n enregistre aucune statistique.';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return 'email:emails:viewown';
    }

    public function getObjectType(): ?string
    {
        return 'email';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'email_id' => ['type' => 'integer', 'description' => 'Identifiant de l email a tester.'],
            'to'       => [
                'type'        => 'string',
                'description' => 'Adresse destinataire. Par defaut celle de l utilisateur connecte.',
            ],
        ], ['email_id']);
    }

    public function execute(array $arguments): array
    {
        $email = $this->emailModel->getEntity((int) ($arguments['email_id'] ?? 0));

        if (!$email instanceof Email) {
            return ['status' => 'error', 'error' => 'Email introuvable.'];
        }

        $user = $this->userHelper->getUser();

        if (!$user instanceof User || null === $user->getId()) {
            return ['status' => 'error', 'error' => 'Utilisateur courant indeterminable.'];
        }

        $to = trim((string) ($arguments['to'] ?? ''));

        if ('' !== $to && !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['status' => 'error', 'error' => sprintf('Adresse invalide : %s', $to)];
        }

        $recipient = '' !== $to ? $to : (string) $user->getEmail();

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'    => 'test_email',
                'email'   => ['id' => $email->getId(), 'name' => $email->getName(), 'subject' => $email->getSubject()],
                'to'      => $recipient,
            ]);
        }

        $errors = $this->emailModel->sendSampleEmailToUser(
            $email,
            [[
                'id'        => $user->getId(),
                'email'     => $recipient,
                'firstname' => (string) $user->getFirstName(),
                'lastname'  => (string) $user->getLastName(),
            ]],
            // Contact fictif : les tokens {contactfield=...} doivent se resoudre
            // sur quelque chose. Passer null fait echouer TokenSubscriber.
            $this->fakeContactHelper->prepareFakeContactWithPrimaryCompany(),
            [],
            [],
            false,
        );

        if (!empty($errors)) {
            return ['status' => 'error', 'error' => implode(' | ', array_map('strval', (array) $errors))];
        }

        return $this->ok([
            'id'   => $email->getId(),
            'name' => $email->getName(),
            'sent_to' => $recipient,
        ]);
    }
}
