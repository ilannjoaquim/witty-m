<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Entity\WittyAttachment;
use MauticPlugin\WittyBundle\Service\Attachment\AttachmentManager;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

/**
 * Retrouve un fichier deja envoye a l'agent, par nom, sans passer par le
 * trombone du tour en cours.
 *
 * L'utilisateur peut uploader un fichier a l'avance (page Fichiers,
 * Controller/FileController.php) puis, dans n'importe quelle conversation,
 * demander a l'agent de l'utiliser par son nom ("utilise logo-ete.png pour
 * l'email") : sans cet outil, l'agent ne connaissait que les pieces jointes
 * du message en cours (cf. la note [Piece jointe : ...] ajoutee par
 * ConversationManager::toMessages()), jamais le reste de la bibliotheque de
 * l'utilisateur.
 */
class ListAttachmentsTool extends AbstractTool
{
    public function __construct(private AttachmentManager $attachments)
    {
    }

    public function getName(): string
    {
        return 'list_attachments';
    }

    public function getDescription(): string
    {
        return "Liste les fichiers deja envoyes a l'agent par l'utilisateur courant (bibliotheque Fichiers), "
            .'pas seulement ceux joints au message en cours. search filtre sur le nom de fichier '
            .'(ex. "logo" retrouve "logo-ete.png"). Pour une image/un document, asset_url est directement '
            .'utilisable (email, landing page, asset) ; pour un tableur ou un texte, appelle ensuite '
            .'read_attachment(id) pour en lire le contenu.';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'search' => ['type' => 'string', 'description' => 'Filtre sur le nom de fichier. Vide pour lister les plus recents.'],
            'limit'  => ['type' => 'integer', 'description' => 'Nombre maximum de resultats (defaut 25, max 100).'],
        ]);
    }

    public function execute(array $arguments): array
    {
        $search = trim((string) ($arguments['search'] ?? ''));
        $limit  = max(1, min(100, (int) ($arguments['limit'] ?? 25)));

        $items = array_map(
            fn (WittyAttachment $attachment): array => [
                'id'         => $attachment->getId(),
                'filename'   => $attachment->getOriginalFilename(),
                'kind'       => $attachment->getKind(),
                'size'       => $attachment->getSize(),
                'asset_url'  => $this->attachments->assetUrl($attachment),
                'uploaded_at' => $attachment->getDateAdded()->format('c'),
            ],
            $this->attachments->listForUser('' !== $search ? $search : null, $limit),
        );

        return $this->ok(['count' => count($items), 'attachments' => $items]);
    }
}
