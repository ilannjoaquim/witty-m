<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\Skill\SkillManager;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

/**
 * Charge le contenu complet d'un skill (playbook/strategie propre a
 * l'entreprise). Les noms et descriptions de tous les skills sont deja dans
 * le prompt systeme (cf. PromptBuilder) ; cet outil n'est appele que quand
 * l'agent juge un skill pertinent pour la demande en cours, ou quand
 * l'utilisateur le demande explicitement — jamais systematiquement, pour ne
 * pas gonfler le contexte de chaque tour.
 */
class ReadSkillTool extends AbstractTool
{
    public function __construct(
        private SkillManager $skills,
    ) {
    }

    public function getName(): string
    {
        return 'read_skill';
    }

    public function getDescription(): string
    {
        return 'Charge le contenu complet d un skill (playbook/strategie de l entreprise, cf. la liste des skills disponibles '
            .'dans les instructions systeme). A utiliser quand un skill semble pertinent pour la demande en cours, ou quand '
            .'l utilisateur demande explicitement de suivre tel skill/playbook/strategie. Ne pas appeler cet outil sans raison : '
            .'seuls les noms et descriptions sont visibles par defaut, pas le contenu.';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'name' => ['type' => 'string', 'description' => 'Nom exact du skill a charger (voir la liste dans les instructions systeme).'],
        ], ['name']);
    }

    public function execute(array $arguments): array
    {
        $name = trim((string) ($arguments['name'] ?? ''));

        if ('' === $name) {
            return ['status' => 'error', 'error' => 'name est obligatoire.'];
        }

        $skill = $this->skills->findByName($name);

        if (null === $skill) {
            $available = array_column($this->skills->listForPrompt(), 'name');

            return [
                'status'    => 'error',
                'error'     => sprintf('Aucun skill nomme "%s".', $name),
                'available' => $available,
            ];
        }

        return $this->ok([
            'name'    => $skill->getName(),
            'content' => $skill->getContent(),
        ]);
    }
}
