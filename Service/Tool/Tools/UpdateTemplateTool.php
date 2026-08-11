<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Entity\WittyTemplate;
use MauticPlugin\WittyBundle\Service\Template\TemplateManager;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Modifie un template existant de la bibliotheque partagee, en place.
 *
 * Identifie par (type, key) — ce que list_email_templates/list_page_templates
 * exposent deja a l'agent — jamais par un id numerique, que l'agent ne voit
 * jamais pour un template. Meme gouvernance que create_template : n'appeler
 * que si l'utilisateur demande explicitement de modifier un template, jamais
 * de sa propre initiative.
 *
 * Chaque champ fourni remplace integralement l'existant (pas de fusion) :
 * pour html/rules/placeholders, renvoyer la version complete souhaitee, pas
 * un fragment. Utiliser list_email_templates/list_page_templates avec le
 * parametre template pour recuperer l'etat actuel avant de le modifier.
 */
class UpdateTemplateTool extends AbstractTool
{
    public function __construct(
        private TemplateManager $manager,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'update_template';
    }

    public function getDescription(): string
    {
        return 'Modifie un template existant (email ou landing page) de la bibliotheque partagee, en place. Identifie '
            .'par type + key (voir list_email_templates/list_page_templates). Chaque champ fourni remplace l existant '
            .'en entier. A utiliser uniquement si l utilisateur demande explicitement de modifier un template.';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getObjectType(): ?string
    {
        return 'template';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'type'        => ['type' => 'string', 'enum' => [WittyTemplate::TYPE_EMAIL, WittyTemplate::TYPE_PAGE]],
            'key'         => ['type' => 'string', 'description' => 'Cle du template a modifier.'],
            'name'        => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'goal'        => ['type' => 'string'],
            'rules'       => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Remplace integralement les regles existantes si fourni.'],
            'placeholders' => [
                'type'        => 'array',
                'description' => 'Remplace integralement les emplacements existants si fourni.',
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'key'      => ['type' => 'string'],
                        'label'    => ['type' => 'string'],
                        'guidance' => ['type' => 'string'],
                        'example'  => ['type' => 'string'],
                        'default'  => ['type' => 'string'],
                        'context'  => ['type' => 'string', 'enum' => ['html', 'html_br', 'js']],
                    ],
                    'required' => ['key'],
                ],
            ],
            'html' => ['type' => 'string', 'description' => 'Remplace integralement le HTML existant si fourni (document complet, pas un fragment).'],
        ], ['type', 'key']);
    }

    public function execute(array $arguments): array
    {
        $type = (string) ($arguments['type'] ?? '');
        $key  = trim((string) ($arguments['key'] ?? ''));

        if (!in_array($type, [WittyTemplate::TYPE_EMAIL, WittyTemplate::TYPE_PAGE], true)) {
            return ['status' => 'error', 'error' => sprintf('Type inconnu : %s.', $type)];
        }

        if ('' === $key) {
            return ['status' => 'error', 'error' => 'key est obligatoire.'];
        }

        $template = $this->manager->findByTypeAndKey($type, $key);

        if (null === $template) {
            return ['status' => 'error', 'error' => sprintf("Template introuvable : %s/%s.", $type, $key)];
        }

        $changes = [];

        if (array_key_exists('name', $arguments) && '' !== trim((string) $arguments['name'])) {
            $changes['name'] = trim((string) $arguments['name']);
        }

        if (array_key_exists('description', $arguments)) {
            $changes['description'] = trim((string) $arguments['description']);
        }

        if (array_key_exists('goal', $arguments)) {
            $changes['goal'] = (string) $arguments['goal'];
        }

        if (array_key_exists('rules', $arguments)) {
            $changes['rules'] = TemplateManager::normalizeRules((array) $arguments['rules']);
        }

        if (array_key_exists('placeholders', $arguments)) {
            $placeholders = TemplateManager::normalizePlaceholders((array) $arguments['placeholders']);

            if (is_string($placeholders)) {
                return ['status' => 'error', 'error' => $placeholders];
            }

            $changes['placeholders'] = $placeholders;
        }

        if (array_key_exists('html', $arguments) && '' !== trim((string) $arguments['html'])) {
            $changes['html'] = (string) $arguments['html'];
        }

        if ([] === $changes) {
            return ['status' => 'error', 'error' => 'Aucune modification demandee.'];
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired(array_filter([
                'type'          => $type,
                'key'           => $key,
                'objet'         => $template->getName(),
                'champs'        => array_keys($changes),
                'html_longueur' => isset($changes['html']) ? strlen($changes['html']).' caracteres' : null,
            ], static fn ($value): bool => null !== $value));
        }

        if (isset($changes['name'])) {
            $template->setName($changes['name']);
        }

        if (isset($changes['description'])) {
            $template->setDescription($changes['description']);
        }

        if (isset($changes['goal'])) {
            $template->setGoal($changes['goal']);
        }

        if (isset($changes['rules'])) {
            $template->setRules($changes['rules']);
        }

        if (isset($changes['placeholders'])) {
            $template->setPlaceholders($changes['placeholders']);
        }

        if (isset($changes['html'])) {
            $template->setHtml($changes['html']);
        }

        $this->manager->save($template);

        return $this->ok([
            'type'    => $type,
            'key'     => $template->getKey(),
            'name'    => $template->getName(),
            'changes' => array_keys($changes),
        ]);
    }
}
