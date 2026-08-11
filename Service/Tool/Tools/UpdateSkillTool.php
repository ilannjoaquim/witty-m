<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\Skill\SkillManager;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Modifie un skill existant, en place.
 *
 * Identifie par son nom exact (insensible a la casse, cf.
 * SkillManager::findByName()) — ce que read_skill utilise deja, et ce que le
 * prompt systeme expose en permanence (liste des noms+descriptions) — jamais
 * par un id numerique que l'agent ne voit nulle part. Meme gouvernance que
 * update_template : n'appeler que sur demande explicite de l'utilisateur.
 *
 * Chaque champ fourni remplace integralement l'existant (pas de fusion) :
 * pour content, renvoyer la version complete souhaitee, pas un fragment.
 */
class UpdateSkillTool extends AbstractTool
{
    public function __construct(
        private SkillManager $skills,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'update_skill';
    }

    public function getDescription(): string
    {
        return 'Modifie un skill existant (playbook/strategie de l entreprise), identifie par son nom exact (voir '
            .'read_skill / la liste des skills dans les instructions systeme). Chaque champ fourni remplace l existant '
            .'en entier. A utiliser uniquement si l utilisateur demande explicitement de modifier un skill.';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getObjectType(): ?string
    {
        return 'skill';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'name'        => ['type' => 'string', 'description' => 'Nom exact du skill a modifier.'],
            'new_name'    => ['type' => 'string', 'description' => 'Nouveau nom, si renommage.'],
            'description' => ['type' => 'string'],
            'content'     => ['type' => 'string', 'description' => 'Remplace integralement le contenu existant si fourni (document complet, pas un fragment).'],
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
            return ['status' => 'error', 'error' => sprintf('Aucun skill nomme "%s".', $name)];
        }

        $changes = [];

        if (array_key_exists('new_name', $arguments) && '' !== trim((string) $arguments['new_name'])) {
            $changes['name'] = trim((string) $arguments['new_name']);
        }

        if (array_key_exists('description', $arguments)) {
            $changes['description'] = trim((string) $arguments['description']);
        }

        if (array_key_exists('content', $arguments) && '' !== trim((string) $arguments['content'])) {
            $changes['content'] = (string) $arguments['content'];
        }

        if ([] === $changes) {
            return ['status' => 'error', 'error' => 'Aucune modification demandee.'];
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired(array_filter([
                'name'              => $name,
                'champs'            => array_keys($changes),
                'content_longueur'  => isset($changes['content']) ? strlen($changes['content']).' caracteres' : null,
            ], static fn ($value): bool => null !== $value));
        }

        if (isset($changes['name'])) {
            $skill->setName($changes['name']);
        }

        if (isset($changes['description'])) {
            $skill->setDescription($changes['description']);
        }

        if (isset($changes['content'])) {
            $skill->setContent($changes['content']);
        }

        $this->skills->save($skill);

        return $this->ok([
            'id'      => $skill->getId(),
            'name'    => $skill->getName(),
            'changes' => array_keys($changes),
        ]);
    }
}
