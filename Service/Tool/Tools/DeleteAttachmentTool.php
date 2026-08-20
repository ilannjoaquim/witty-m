<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\Attachment\AttachmentManager;
use MauticPlugin\WittyBundle\Service\Attachment\Exception\AttachmentNotFoundException;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

/**
 * Supprime une piece jointe deja envoyee (chat ou bibliotheque Fichiers),
 * identifiee par son id (voir list_attachments). Meme service que le menu
 * "..." de la page Fichiers (AttachmentManager::delete()) : supprime le
 * fichier physique (ou l'Asset Mautic pour une image/un document/une
 * police) ET la ligne en base.
 *
 * Toujours une confirmation explicite, meme si le mode confirmation global
 * est desactive : comme delete_contact/delete_entity, irreversible.
 */
class DeleteAttachmentTool extends AbstractTool
{
    public function __construct(private AttachmentManager $attachments)
    {
    }

    public function getName(): string
    {
        return 'delete_attachment';
    }

    public function getDescription(): string
    {
        return 'Supprime definitivement une piece jointe deja envoyee (chat ou bibliotheque Fichiers), '
            .'identifiee par son id (voir list_attachments). Irreversible. '
            .'Demande toujours l accord explicite de l utilisateur avant d appeler cet outil avec confirmed=true.';
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
        ], ['attachment_id']);
    }

    public function execute(array $arguments): array
    {
        try {
            $attachment = $this->attachments->resolve((int) ($arguments['attachment_id'] ?? 0));
        } catch (AttachmentNotFoundException $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }

        // Pas de raccourci possible ici, meme si la confirmation globale est
        // desactivee : une suppression ne se rattrape pas.
        if (true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'          => 'attachment',
                'id'            => $attachment->getId(),
                'filename'      => $attachment->getOriginalFilename(),
                'irreversible'  => true,
                'avertissement' => 'Cette suppression est definitive.',
            ]);
        }

        $filename = $attachment->getOriginalFilename();
        $id       = $attachment->getId();

        $this->attachments->delete($attachment);

        return $this->ok([
            'id'       => $id,
            'filename' => $filename,
            'deleted'  => true,
        ]);
    }
}
