<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Model\ListModel;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

class CreateEmailTool extends AbstractTool
{
    public function __construct(
        private EmailModel $emailModel,
        private ListModel $listModel,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'create_email';
    }

    public function getDescription(): string
    {
        return 'Cree un email. type=template pour un email utilisable dans une campagne, '
            .'type=list pour un envoi ponctuel vers un ou plusieurs segments. '
            .'Le HTML doit etre un document complet et peut contenir des tokens Mautic comme {contactfield=firstname}.';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return 'email:emails:create';
    }

    public function getObjectType(): ?string
    {
        return 'email';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'name'       => ['type' => 'string', 'description' => 'Nom interne de l email.'],
            'subject'    => ['type' => 'string', 'description' => 'Objet de l email.'],
            'html'       => ['type' => 'string', 'description' => 'Corps HTML complet.'],
            'type'       => ['type' => 'string', 'enum' => ['template', 'list'], 'description' => 'Defaut template.'],
            'segment_ids' => [
                'type'        => 'array',
                'items'       => ['type' => 'integer'],
                'description' => 'Obligatoire si type=list.',
            ],
            'from_name'    => ['type' => 'string'],
            'from_address' => ['type' => 'string'],
            'is_published' => ['type' => 'boolean', 'description' => 'Defaut false.'],
        ], ['name', 'subject', 'html']);
    }

    public function execute(array $arguments): array
    {
        $name    = trim((string) ($arguments['name'] ?? ''));
        $subject = trim((string) ($arguments['subject'] ?? ''));
        $html    = (string) ($arguments['html'] ?? '');
        $type    = 'list' === ($arguments['type'] ?? 'template') ? 'list' : 'template';

        if ('' === $name || '' === $subject || '' === $html) {
            return ['status' => 'error', 'error' => 'name, subject et html sont obligatoires.'];
        }

        if ('list' === $type && [] === (array) ($arguments['segment_ids'] ?? [])) {
            return ['status' => 'error', 'error' => 'segment_ids est obligatoire pour un email de type list.'];
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'        => 'email',
                'name'        => $name,
                'subject'     => $subject,
                'email_type'  => $type,
                'html_length' => strlen($html),
                'html_excerpt' => mb_substr(strip_tags($html), 0, 300),
            ]);
        }

        $email = new Email();
        $email->setName($name);
        $email->setSubject($subject);
        $email->setCustomHtml($html);
        $email->setEmailType($type);
        $email->setTemplate('blank');
        $email->setIsPublished((bool) ($arguments['is_published'] ?? false));

        if (!empty($arguments['from_name'])) {
            $email->setFromName((string) $arguments['from_name']);
        }

        if (!empty($arguments['from_address'])) {
            $email->setFromAddress((string) $arguments['from_address']);
        }

        $attached = [];

        if ('list' === $type) {
            foreach ((array) ($arguments['segment_ids'] ?? []) as $segmentId) {
                $segment = $this->listModel->getEntity((int) $segmentId);

                if ($segment instanceof LeadList) {
                    $email->addList($segment);
                    $attached[] = $segment->getId();
                }
            }
        }

        $this->emailModel->saveEntity($email);

        return $this->ok([
            'id'       => $email->getId(),
            'name'     => $email->getName(),
            'type'     => $type,
            'segments' => $attached,
            'url'      => '/s/emails/edit/'.$email->getId(),
        ]);
    }
}
