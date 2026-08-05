<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\ProjectBundle\Entity\Project;
use Mautic\ProjectBundle\Model\ProjectModel;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Cree un projet, dossier transverse pour regrouper des objets de types
 * differents (campagnes, emails, segments...). Le rattachement d objets a un
 * projet n est pas gere ici : ca se fait depuis chaque objet.
 */
class CreateProjectTool extends AbstractTool
{
    public function __construct(
        private ProjectModel $projectModel,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'create_project';
    }

    public function getDescription(): string
    {
        return 'Cree un projet : dossier pour regrouper des objets Mautic de types differents.';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return 'project:project:create';
    }

    public function getObjectType(): ?string
    {
        return 'project';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'name'        => ['type' => 'string'],
            'description' => ['type' => 'string'],
        ], ['name']);
    }

    public function execute(array $arguments): array
    {
        $name = trim((string) ($arguments['name'] ?? ''));

        if ('' === $name) {
            return ['status' => 'error', 'error' => 'name est obligatoire.'];
        }

        // Le nom est unique en base (contrainte SQL) mais Mautic ne le verifie
        // qu'a la soumission du formulaire, jamais dans ProjectModel::saveEntity().
        // Sans ce controle, un doublon fait echouer le flush Doctrine, ce qui
        // ferme l'EntityManager pour le reste de la requete (et donc de tous
        // les outils d'ecriture suivants dans le meme tour de l'agent).
        if ($this->projectModel->getRepository()->checkProjectNameExists($name)) {
            return ['status' => 'error', 'error' => sprintf("Un projet nomme '%s' existe deja.", $name)];
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired(['type' => 'project', 'name' => $name]);
        }

        $project = new Project();
        $project->setName($name);
        $project->setDescription((string) ($arguments['description'] ?? ''));

        $this->projectModel->saveEntity($project);

        return $this->ok([
            'id'   => $project->getId(),
            'name' => $project->getName(),
            'url'  => '/s/projects/edit/'.$project->getId(),
        ]);
    }
}
