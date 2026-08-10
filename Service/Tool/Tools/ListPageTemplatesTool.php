<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\Template\PageTemplateLibrary;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

class ListPageTemplatesTool extends AbstractTool
{
    public function __construct(private PageTemplateLibrary $library)
    {
    }

    public function getName(): string
    {
        return 'list_page_templates';
    }

    public function getDescription(): string
    {
        return 'Liste les templates de landing page livres avec le plugin. Appeler cet outil AVANT create_page_from_template : '
            .'il renvoie, pour chaque emplacement, la consigne de redaction a respecter et un exemple. '
            .'Passer template pour obtenir le detail complet d un seul template.';
    }

    public function getRequiredPermission(): ?string
    {
        return 'page:pages:viewown';
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
                    'template'     => $template->getKey(),
                    'name'         => $template->getName(),
                    'description'  => $template->getDescription(),
                    'placeholders' => count($template->getPlaceholders()),
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
            'template'     => $template->getKey(),
            'name'         => $template->getName(),
            'description'  => $template->getDescription(),
            'goal'         => $template->getGoal(),
            'rules'        => $template->getRules(),
            'placeholders' => $template->describePlaceholders(),
        ]);
    }
}
