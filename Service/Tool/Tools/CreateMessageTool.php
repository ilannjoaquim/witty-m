<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\ChannelBundle\Entity\Channel;
use Mautic\ChannelBundle\Entity\Message;
use Mautic\ChannelBundle\Model\MessageModel;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Cree un message marketing (menu "Channels") : un meme envoi decline sur
 * plusieurs canaux (email, sms...), chacun pointant vers un contenu deja
 * existant sur ce canal.
 *
 * Les canaux disponibles dependent des bundles installes (email, sms...) : on
 * les decouvre via list_channels plutot que de les coder en dur.
 */
class CreateMessageTool extends AbstractTool
{
    public function __construct(
        private MessageModel $messageModel,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'create_message';
    }

    public function getDescription(): string
    {
        return 'Cree un message marketing multi-canal (Channels). Appeler d abord avec list_channels=true '
            .'pour connaitre les canaux disponibles, puis fournir pour chacun le contenu existant a utiliser '
            .'(ex. channel_id = identifiant d un email pour le canal email).';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return 'channel:messages:create';
    }

    public function getObjectType(): ?string
    {
        return 'message';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'list_channels' => ['type' => 'boolean', 'description' => 'true pour lister les canaux disponibles, sans rien creer.'],
            'name'          => ['type' => 'string'],
            'description'   => ['type' => 'string'],
            'is_published'  => ['type' => 'boolean', 'description' => 'Defaut false.'],
            'channels' => [
                'type'        => 'array',
                'description' => 'Declinaisons par canal.',
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'channel'    => ['type' => 'string', 'description' => 'Cle du canal, obtenue via list_channels.'],
                        'channel_id' => ['type' => 'integer', 'description' => 'Identifiant du contenu existant sur ce canal (ex. email_id).'],
                    ],
                    'required' => ['channel', 'channel_id'],
                ],
            ],
        ], []);
    }

    public function execute(array $arguments): array
    {
        $available = $this->messageModel->getChannels();

        if (true === ($arguments['list_channels'] ?? false)) {
            return $this->ok(['channels' => array_keys($available)]);
        }

        $name     = trim((string) ($arguments['name'] ?? ''));
        $channels = array_values((array) ($arguments['channels'] ?? []));

        if ('' === $name || [] === $channels) {
            return ['status' => 'error', 'error' => 'name et channels sont obligatoires.'];
        }

        foreach ($channels as $entry) {
            $channel = (string) ($entry['channel'] ?? '');

            if (!isset($available[$channel])) {
                return [
                    'status' => 'error',
                    'error'  => sprintf('Canal inconnu : %s. Canaux disponibles : %s', $channel, implode(', ', array_keys($available))),
                ];
            }
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'     => 'message',
                'name'     => $name,
                'channels' => array_map(static fn (array $c): string => sprintf('%s -> #%d', $c['channel'], (int) ($c['channel_id'] ?? 0)), $channels),
            ]);
        }

        $message = new Message();
        $message->setName($name);
        $message->setDescription((string) ($arguments['description'] ?? ''));
        $message->setIsPublished((bool) ($arguments['is_published'] ?? false));

        foreach ($channels as $entry) {
            $channelType = (string) $entry['channel'];

            $channel = new Channel();
            $channel->setMessage($message);
            $channel->setChannel($channelType);
            $channel->setChannelId((int) ($entry['channel_id'] ?? 0));
            $channel->setIsEnabled(true);
            $channel->setProperties([]);

            $message->addChannel($channel);
        }

        $this->messageModel->saveEntity($message);

        return $this->ok([
            'id'       => $message->getId(),
            'name'     => $message->getName(),
            'channels' => count($channels),
            'url'      => '/s/messages/edit/'.$message->getId(),
        ]);
    }
}
