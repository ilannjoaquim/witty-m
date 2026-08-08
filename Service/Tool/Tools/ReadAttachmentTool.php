<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\Attachment\AttachmentManager;
use MauticPlugin\WittyBundle\Service\Attachment\Exception\AttachmentNotFoundException;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

/**
 * Inspecte une piece jointe du chat par son id (voir la note
 * "[Piece jointe : ...]" ajoutee au message utilisateur, cf. AgentRunner) avant
 * de l'exploiter — ex. voir les en-tetes d'un tableur avant d'appeler
 * import_leads_from_file, ou lire le contenu d'un fichier texte joint.
 */
class ReadAttachmentTool extends AbstractTool
{
    public function __construct(private AttachmentManager $attachments)
    {
    }

    public function getName(): string
    {
        return 'read_attachment';
    }

    public function getDescription(): string
    {
        return "Lit une piece jointe du chat par son id. Texte : contenu (tronque si long). "
            .'Tableur : en-tetes + apercu des premieres lignes. '
            ."Image/document : pas de lecture textuelle, seulement l'URL de l'asset a reutiliser (email, landing page, asset).";
    }

    public function getSchema(): array
    {
        return $this->schema([
            'attachment_id' => ['type' => 'integer', 'description' => 'Identifiant de la piece jointe.'],
        ], ['attachment_id']);
    }

    public function execute(array $arguments): array
    {
        $id = (int) ($arguments['attachment_id'] ?? 0);

        try {
            $attachment = $this->attachments->resolve($id);
        } catch (AttachmentNotFoundException $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }

        return $this->ok([
            'id'       => $attachment->getId(),
            'filename' => $attachment->getOriginalFilename(),
            'kind'     => $attachment->getKind(),
        ] + $this->attachments->readPreview($attachment));
    }
}
