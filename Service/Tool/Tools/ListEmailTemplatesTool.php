<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\Template\EmailTemplateLibrary;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

/**
 * Expose la bibliotheque de templates au modele.
 *
 * Sans le detail des emplacements, le modele remplirait le template au jugé.
 * Les consignes rendues ici sont celles ecrites dans le manifeste : c'est la
 * partie qui fait la difference entre un email structure et un email generique.
 */
class ListEmailTemplatesTool extends AbstractTool
{
    public function __construct(private EmailTemplateLibrary $library)
    {
    }

    public function getName(): string
    {
        return 'list_email_templates';
    }

    public function getDescription(): string
    {
        return 'Liste les templates d email livres avec le plugin. Appeler cet outil AVANT create_email_from_template : '
            .'il renvoie, pour chaque emplacement, la consigne de redaction a respecter et un exemple. '
            .'Passer template pour obtenir le detail complet d un seul template.';
    }

    public function getRequiredPermission(): ?string
    {
        return 'email:emails:viewown';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'template' => [
                'type'        => 'string',
                'description' => 'Cle du template a detailler. Sans cet argument, renvoie la liste des templates disponibles.',
            ],
        ], []);
    }

    public function execute(array $arguments): array
    {
        $key = trim((string) ($arguments['template'] ?? ''));

        if ('' === $key) {
            $templates = [];

            foreach ($this->library->all() as $template) {
                $templates[] = [
                    'template'    => $template->key,
                    'name'        => $template->name,
                    'description' => $template->description,
                    'placeholders'=> count($template->placeholders),
                ];
            }

            return $this->ok(['count' => count($templates), 'templates' => $templates]);
        }

        $template = $this->library->get($key);

        if (null === $template) {
            return [
                'status' => 'error',
                'error'  => sprintf('Template inconnu : %s. Disponibles : %s', $key, implode(', ', array_keys($this->library->all()))),
            ];
        }

        return $this->ok([
            'template'     => $template->key,
            'name'         => $template->name,
            'description'  => $template->description,
            'goal'         => $template->goal,
            'rules'        => $template->rules,
            'placeholders' => $template->describePlaceholders(),
        ]);
    }
}
