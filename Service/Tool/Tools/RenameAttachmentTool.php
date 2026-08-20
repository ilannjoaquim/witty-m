<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\Attachment\AttachmentManager;
use MauticPlugin\WittyBundle\Service\Attachment\Exception\AttachmentInvalidException;
use MauticPlugin\WittyBundle\Service\Attachment\Exception\AttachmentNotFoundException;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Renomme une piece jointe du chat/de la bibliotheque Fichiers, par son id
 * (voir list_attachments/read_attachment). Meme service que le menu "..." de
 * la page Fichiers (Service/Attachment/AttachmentManager.php::rename()) :
 * aucune logique dupliquee, seulement un point d'entree cote agent.
 *
 * Aucune permission Mautic dediee (comme read_attachment/list_attachments) :
 * une piece jointe est scopee par utilisateur, pas par role — l'appartenance
 * elle-meme (verifiee par AttachmentManager::resolve()) est le seul controle
 * d'acces necessaire.
 */
class RenameAttachmentTool extends AbstractTool
{
    public function __construct(
        private AttachmentManager $attachments,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'rename_attachment';
    }

    public function getDescription(): string
    {
        return 'Renomme une piece jointe deja envoyee (chat ou bibliotheque Fichiers), identifiee par son id '
            .'(voir list_attachments). L extension reelle du fichier est toujours conservee automatiquement, '
            .'quoi que tu fournisses dans filename (ex. renommer un CSV en "rapport.pdf" donne "rapport.csv") : '
            .'un fichier ne change jamais de type via ce nom affiche.';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getObjectType(): ?string
    {
        return null;
    }

    public function getSchema(): array
    {
        return $this->schema([
            'attachment_id' => ['type' => 'integer', 'description' => 'Identifiant de la piece jointe (voir list_attachments).'],
            'filename'      => ['type' => 'string', 'description' => 'Nouveau nom. L extension reelle du fichier est reappliquee automatiquement.'],
        ], ['attachment_id', 'filename']);
    }

    public function execute(array $arguments): array
    {
        try {
            $attachment = $this->attachments->resolve((int) ($arguments['attachment_id'] ?? 0));
        } catch (AttachmentNotFoundException $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }

        $newName = (string) ($arguments['filename'] ?? '');

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'         => 'attachment',
                'id'           => $attachment->getId(),
                'ancien_nom'   => $attachment->getOriginalFilename(),
                'nouveau_nom'  => $newName,
            ]);
        }

        try {
            $attachment = $this->attachments->rename($attachment, $newName);
        } catch (AttachmentInvalidException $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }

        return $this->ok([
            'id'       => $attachment->getId(),
            'filename' => $attachment->getOriginalFilename(),
        ]);
    }
}
