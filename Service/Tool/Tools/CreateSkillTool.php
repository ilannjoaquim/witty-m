<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Entity\WittySkill;
use MauticPlugin\WittyBundle\Service\Skill\SkillManager;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Cree un nouveau skill (playbook/strategie propre a l'entreprise) dans la
 * bibliotheque partagee (section Witty > Skills) : nom + description restent
 * en permanence dans le prompt systeme pour TOUTES les conversations futures,
 * le contenu complet se charge a la demande via read_skill (cf. PromptBuilder).
 *
 * A n'appeler QUE si l'utilisateur demande explicitement de creer/enregistrer
 * un skill (jamais de sa propre initiative en repondant a une question
 * ponctuelle) : meme gouvernance que create_template — rediger un skill a
 * partir d'une consigne ou d'un exemple fourni par l'utilisateur est le
 * travail de l'agent, pas d'un humain qui devrait sinon taper tout le contenu
 * a la main dans l'UI.
 */
class CreateSkillTool extends AbstractTool
{
    public function __construct(
        private SkillManager $skills,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'create_skill';
    }

    public function getDescription(): string
    {
        return 'Cree un nouveau skill (playbook/strategie de l entreprise) dans la bibliotheque partagee (section '
            .'Witty > Skills). A utiliser uniquement si l utilisateur demande explicitement de creer/enregistrer un '
            .'skill pour usage futur, jamais de ta propre initiative.';
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
            'name'        => ['type' => 'string', 'description' => 'Nom du skill.'],
            'description' => ['type' => 'string', 'description' => 'Resume court, toujours visible par l agent (defaut : debut du contenu).'],
            'content'     => ['type' => 'string', 'description' => 'Contenu complet du skill (texte libre : playbook, strategie, consignes...).'],
        ], ['name', 'content']);
    }

    public function execute(array $arguments): array
    {
        $name        = trim((string) ($arguments['name'] ?? ''));
        $content     = (string) ($arguments['content'] ?? '');
        $description = trim((string) ($arguments['description'] ?? ''));

        if ('' === $name) {
            return ['status' => 'error', 'error' => 'name est obligatoire.'];
        }

        if ('' === trim($content)) {
            return ['status' => 'error', 'error' => 'content est obligatoire.'];
        }

        // La recherche par nom (read_skill, update_skill) suppose un nom
        // unique : contrairement a l'UI (qui ne l'impose pas), on refuse ici
        // un doublon plutot que de creer une ambiguite que l'agent ne saurait
        // pas resoudre lui-meme ensuite.
        if (null !== $this->skills->findByName($name)) {
            return ['status' => 'error', 'error' => sprintf('Un skill nomme "%s" existe deja. Utilise update_skill pour le modifier.', $name)];
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'name'             => $name,
                'description'      => '' !== $description ? $description : mb_substr($content, 0, 160),
                'content_longueur' => strlen($content).' caracteres',
            ]);
        }

        $skill = new WittySkill();
        $skill->setName($name);
        $skill->setDescription('' !== $description ? $description : mb_substr($content, 0, 160));
        $skill->setContent($content);

        $this->skills->save($skill);

        return $this->ok([
            'id'   => $skill->getId(),
            'name' => $skill->getName(),
        ]);
    }
}
