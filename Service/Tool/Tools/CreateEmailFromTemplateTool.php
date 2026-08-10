<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Model\ListModel;
use MauticPlugin\WittyBundle\Service\Template\EmailTemplateLibrary;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Cree un email a partir d'un template gere depuis la section Witty > Templates.
 *
 * La substitution est faite ici et non par le modele : lui faire recracher
 * 1 200 lignes de HTML compile reviendrait a lui demander de recopier un
 * document sans faute, ce qu'aucun modele ne fait de facon fiable. Il fournit
 * le texte de chaque bloc, le plugin garantit la structure.
 */
class CreateEmailFromTemplateTool extends AbstractTool
{
    /** Emplacements attendant une URL : verifies avant insertion dans un href/src. */
    private const URL_KEYS = ['REGISTRATION_URL', 'LOGO_URL', 'HERO_IMAGE_URL'];

    public function __construct(
        private EmailTemplateLibrary $library,
        private EmailModel $emailModel,
        private ListModel $listModel,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'create_email_from_template';
    }

    public function getDescription(): string
    {
        return 'Cree un email a partir d un template du plugin. Appeler d abord list_email_templates pour '
            .'connaitre les emplacements et leurs consignes, puis fournir ici le texte de chaque emplacement '
            .'dans values. Le plugin assemble le HTML : ne pas tenter de reecrire le template.';
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
            'template' => ['type' => 'string', 'description' => 'Cle du template, ex. webinar.'],
            'name'     => ['type' => 'string', 'description' => 'Nom interne de l email dans Mautic.'],
            'subject'  => ['type' => 'string', 'description' => 'Objet de l email. 45 caracteres maximum de preference.'],
            'values'   => [
                'type'        => 'object',
                'description' => 'Texte de chaque emplacement, indexe par cle (HEADLINE, PROBLEM, ...). '
                    .'Texte brut uniquement : le balisage est fourni par le template et toute valeur est echappee.',
                'additionalProperties' => ['type' => 'string'],
            ],
            'type' => [
                'type'        => 'string',
                'enum'        => ['template', 'list'],
                'description' => 'template pour un email utilisable en campagne, list pour un envoi ponctuel. Defaut template.',
            ],
            'segment_ids' => [
                'type'        => 'array',
                'items'       => ['type' => 'integer'],
                'description' => 'Obligatoire si type=list.',
            ],
            'from_name'    => ['type' => 'string'],
            'from_address' => ['type' => 'string'],
            'is_published' => ['type' => 'boolean', 'description' => 'Defaut false.'],
        ], ['template', 'name', 'subject', 'values']);
    }

    public function execute(array $arguments): array
    {
        $key      = trim((string) ($arguments['template'] ?? ''));
        $template = $this->library->get($key);

        if (null === $template) {
            return [
                'status' => 'error',
                'error'  => sprintf('Template inconnu : %s. Disponibles : %s', $key, implode(', ', array_keys($this->library->all()))),
            ];
        }

        $name    = trim((string) ($arguments['name'] ?? ''));
        $subject = trim((string) ($arguments['subject'] ?? ''));
        $values  = (array) ($arguments['values'] ?? []);
        $type    = 'list' === ($arguments['type'] ?? 'template') ? 'list' : 'template';

        if ('' === $name || '' === $subject) {
            return ['status' => 'error', 'error' => 'name et subject sont obligatoires.'];
        }

        if ('list' === $type && [] === (array) ($arguments['segment_ids'] ?? [])) {
            return ['status' => 'error', 'error' => 'segment_ids est obligatoire pour un email de type list.'];
        }

        $unknown = array_diff(array_map('strtoupper', array_keys($values)), $template->getPlaceholderKeys());

        if ([] !== $unknown) {
            return [
                'status' => 'error',
                'error'  => sprintf('Emplacements inconnus : %s. Utiliser list_email_templates.', implode(', ', $unknown)),
            ];
        }

        foreach (self::URL_KEYS as $urlKey) {
            $url = trim((string) ($values[$urlKey] ?? $values[strtolower($urlKey)] ?? ''));

            if ('' !== $url && !preg_match('#^https?://#i', $url)) {
                return ['status' => 'error', 'error' => sprintf('%s doit etre une URL http(s) : %s', $urlKey, $url)];
            }
        }

        $rendered = EmailTemplateLibrary::render($template, array_map('strval', $values));

        if ([] !== $rendered['missing']) {
            return [
                'status'  => 'error',
                'error'   => sprintf('Emplacements obligatoires manquants : %s', implode(', ', $rendered['missing'])),
                'missing' => $rendered['missing'],
            ];
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'     => 'email',
                'template' => $template->getName(),
                'name'     => $name,
                'subject'  => $subject,
                'apercu'   => array_intersect_key(
                    array_change_key_case($values, CASE_UPPER),
                    array_flip(['HEADLINE', 'SUBHEADLINE', 'APPOINTMENT', 'CTA_PRIMARY', 'REGISTRATION_URL']),
                ),
            ]);
        }

        $email = new Email();
        $email->setName($name);
        $email->setSubject($subject);
        $email->setCustomHtml($rendered['html']);
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
            'template' => $template->getKey(),
            'type'     => $type,
            'segments' => $attached,
            'url'      => '/s/emails/edit/'.$email->getId(),
            'note'     => 'Email cree non publie, en HTML brut (pas de source MJML : le template est gere en HTML, cf. section Witty > Templates).',
        ]);
    }
}
