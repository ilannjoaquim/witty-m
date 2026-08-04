<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\PlugNmeet\Exception\PlugNmeetException;
use MauticPlugin\WittyBundle\Service\PlugNmeet\RecordingToAssetConverter;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Republie un enregistrement plugNmeet comme Asset Mautic local : c est ce qui
 * permet de le joindre a un email (le lien de telechargement plugNmeet est a
 * jeton, courte duree de vie, inutilisable dans un envoi differe).
 */
class ConvertMeetRecordingToAssetTool extends AbstractTool
{
    public function __construct(
        private RecordingToAssetConverter $converter,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'convert_meet_recording_to_asset';
    }

    public function getDescription(): string
    {
        return 'Telecharge un enregistrement plugNmeet et le republie comme Asset Mautic, '
            .'pour pouvoir le joindre a un email ou le referencer dans une landing page.';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getObjectType(): ?string
    {
        return 'asset';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'record_id' => ['type' => 'string', 'description' => 'Identifiant de l enregistrement, obtenu via list_meet_recordings.'],
            'title'     => ['type' => 'string', 'description' => 'Titre de l Asset cree. Deduit de l enregistrement si absent.'],
        ], ['record_id']);
    }

    public function execute(array $arguments): array
    {
        $recordId = trim((string) ($arguments['record_id'] ?? ''));
        $title    = trim((string) ($arguments['title'] ?? ''));

        if ('' === $recordId) {
            return ['status' => 'error', 'error' => 'record_id est obligatoire.'];
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'      => 'meet_recording_to_asset',
                'record_id' => $recordId,
                'title'     => '' !== $title ? $title : $recordId,
                'note'      => 'Le telechargement peut prendre du temps pour un enregistrement long.',
            ]);
        }

        try {
            $asset = $this->converter->convert($recordId, $title);
        } catch (PlugNmeetException $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }

        return $this->ok([
            'id'    => $asset->getId(),
            'title' => $asset->getTitle(),
            'url'   => '/s/assets/edit/'.$asset->getId(),
        ]);
    }
}
