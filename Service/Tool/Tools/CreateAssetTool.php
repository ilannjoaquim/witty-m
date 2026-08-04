<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\AssetBundle\Entity\Asset;
use Mautic\AssetBundle\Model\AssetModel;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Cree un asset a partir d une URL distante.
 *
 * L agent n a pas de canal d upload binaire (c est un outil JSON) : seul le
 * mode "remote" de Mautic (fichier heberge ailleurs, ex. CDN) est donc
 * accessible ici. L upload direct reste a faire depuis l interface Mautic.
 */
class CreateAssetTool extends AbstractTool
{
    public function __construct(
        private AssetModel $assetModel,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'create_asset';
    }

    public function getDescription(): string
    {
        return 'Cree un asset telechargeable Mautic a partir d un fichier deja heberge ailleurs (remote_url). '
            .'Ne permet pas d uploader un fichier binaire : pour ca, passer par l interface Mautic.';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return 'asset:assets:create';
    }

    public function getObjectType(): ?string
    {
        return 'asset';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'title'         => ['type' => 'string', 'description' => 'Titre affiche. Deduit du nom de fichier si absent.'],
            'remote_url'    => ['type' => 'string', 'description' => 'URL publique du fichier a referencer.'],
            'description'   => ['type' => 'string'],
            'language'      => ['type' => 'string', 'description' => "Code langue, ex. fr, en. Defaut 'fr'."],
            'is_published'  => ['type' => 'boolean', 'description' => 'Defaut false.'],
        ], ['remote_url']);
    }

    public function execute(array $arguments): array
    {
        $remoteUrl = trim((string) ($arguments['remote_url'] ?? ''));

        if ('' === $remoteUrl || false === filter_var($remoteUrl, FILTER_VALIDATE_URL)) {
            return ['status' => 'error', 'error' => 'remote_url est obligatoire et doit etre une URL valide.'];
        }

        $title = trim((string) ($arguments['title'] ?? ''));

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'       => 'asset',
                'title'      => '' !== $title ? $title : basename($remoteUrl),
                'remote_url' => $remoteUrl,
            ]);
        }

        $asset = new Asset();
        $asset->setStorageLocation('remote');
        $asset->setRemotePath($remoteUrl);

        if ('' !== $title) {
            $asset->setTitle($title);
        }

        $asset->setDescription((string) ($arguments['description'] ?? ''));
        $asset->setLanguage((string) ($arguments['language'] ?? 'fr'));
        $asset->setIsPublished((bool) ($arguments['is_published'] ?? false));

        // Meme sequence que AssetController : deduit le nom de fichier et les
        // metadonnees (taille, type mime) depuis l URL distante avant sauvegarde.
        $asset->preUpload();
        $asset->upload();

        $this->assetModel->saveEntity($asset);

        return $this->ok([
            'id'    => $asset->getId(),
            'title' => $asset->getTitle(),
            'url'   => '/s/assets/edit/'.$asset->getId(),
        ]);
    }
}
