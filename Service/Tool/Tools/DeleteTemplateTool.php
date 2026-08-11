<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Entity\WittyTemplate;
use MauticPlugin\WittyBundle\Service\Template\TemplateManager;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

/**
 * Supprime definitivement un template de la bibliotheque partagee.
 *
 * Identifie par (type, key), comme update_template. Exige confirmed=true
 * MEME SI le mode confirmation global est desactive : meme regle que
 * delete_entity/manage_tags(delete)/end_meet_room/delete_meet_recording (cf.
 * README) — action irreversible, et celle-ci retire un template a TOUTES les
 * conversations futures, pas seulement a l'objet en cours.
 */
class DeleteTemplateTool extends AbstractTool
{
    public function __construct(private TemplateManager $manager)
    {
    }

    public function getName(): string
    {
        return 'delete_template';
    }

    public function getDescription(): string
    {
        return 'Supprime definitivement un template (email ou landing page) de la bibliotheque partagee. Identifie par '
            .'type + key. Irreversible : exige confirmed=true meme si le mode confirmation global est desactive. '
            .'A utiliser uniquement si l utilisateur demande explicitement de supprimer un template.';
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
            'type' => ['type' => 'string', 'enum' => [WittyTemplate::TYPE_EMAIL, WittyTemplate::TYPE_PAGE]],
            'key'  => ['type' => 'string', 'description' => 'Cle du template a supprimer.'],
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

        if (true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'  => $type,
                'key'   => $key,
                'objet' => $template->getName(),
                'note'  => 'Suppression definitive, retire ce template pour toutes les conversations futures.',
            ]);
        }

        $this->manager->delete($template);

        return $this->ok(['type' => $type, 'key' => $key]);
    }
}
