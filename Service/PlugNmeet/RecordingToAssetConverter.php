<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\PlugNmeet;

use Mautic\AssetBundle\Entity\Asset;
use Mautic\AssetBundle\Model\AssetModel;
use MauticPlugin\WittyBundle\Service\PlugNmeet\Exception\PlugNmeetException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Telecharge un enregistrement plugNmeet en flux et le republie comme Asset
 * Mautic local : l'URL de telechargement plugNmeet (a jeton, courte duree de
 * vie) ne convient pas pour un partage par email, un Asset local si.
 *
 * Partage entre VideoconferenceController (bouton "Convertir en Asset") et
 * l'outil agent convert_meet_recording_to_asset : meme logique, deux points
 * d'entree.
 */
class RecordingToAssetConverter
{
    public function __construct(
        private PlugNmeetClient $client,
        private AssetModel $assetModel,
    ) {
    }

    /**
     * @throws PlugNmeetException
     */
    public function convert(string $recordId, string $title = ''): Asset
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'witty_rec_');

        if (false === $tmpPath) {
            throw new PlugNmeetException('Impossible de creer un fichier temporaire.');
        }

        try {
            $meta = $this->client->downloadRecordingToFile($recordId, $tmpPath);

            $extension = $this->extensionFromContentType((string) ($meta['content_type'] ?? ''));
            $filename  = ('' !== $title ? $title : $recordId).'.'.$extension;

            $uploadedFile = new UploadedFile($tmpPath, $filename, $meta['content_type'] ?? null, null, true);

            $asset = new Asset();
            $asset->setTitle('' !== $title ? $title : sprintf('Enregistrement %s', $recordId));
            $asset->setDescription(sprintf('Importe depuis plugNmeet (enregistrement %s).', $recordId));
            $asset->setFile($uploadedFile);
            $asset->setIsPublished(false);
            // Asset::upload() nettoie un dossier temporaire derive de tempId, sans
            // jamais verifier qu'il est defini : sans lui, getAbsoluteTempDir()
            // renvoie null et Filesystem::remove(null) explose. tempId n'existe
            // normalement que via le flux d'upload chunk-par-chunk du navigateur,
            // absent ici puisqu'on construit le fichier directement cote serveur.
            $asset->setTempId(uniqid('witty_', true));
            $asset->preUpload();
            $asset->upload();

            $this->assetModel->saveEntity($asset);

            return $asset;
        } finally {
            if (file_exists($tmpPath)) {
                unlink($tmpPath);
            }
        }
    }

    private function extensionFromContentType(string $contentType): string
    {
        $contentType = strtolower(trim(explode(';', $contentType)[0] ?? ''));

        return match ($contentType) {
            'video/mp4'  => 'mp4',
            'video/webm' => 'webm',
            'audio/mpeg' => 'mp3',
            'audio/ogg'  => 'ogg',
            default      => 'mp4',
        };
    }
}
