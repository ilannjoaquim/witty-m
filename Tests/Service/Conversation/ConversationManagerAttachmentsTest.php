<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Conversation;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use MauticPlugin\WittyBundle\Entity\WittyAttachment;
use MauticPlugin\WittyBundle\Entity\WittyConversation;
use MauticPlugin\WittyBundle\Entity\WittyMessage;
use MauticPlugin\WittyBundle\Service\Attachment\AttachmentManager;
use MauticPlugin\WittyBundle\Service\Conversation\ConversationManager;
use MauticPlugin\WittyBundle\Service\Llm\Dto\Message;
use PHPUnit\Framework\TestCase;

/**
 * Le contenu persiste (WittyMessage::content) doit rester exactement ce que
 * l'utilisateur a tape — c'est toDisplayTranscript() qui en depend pour
 * l'affichage. La mention des pieces jointes n'est ajoutee que dans ce que le
 * modele recoit (toMessages()), jamais dans ce qui est stocke ou affiche.
 */
class ConversationManagerAttachmentsTest extends TestCase
{
    public function testToMessagesAppendsAttachmentNoteWithoutMutatingStoredContent(): void
    {
        $conversation = new WittyConversation();
        $message      = Message::user('Voici la liste');
        $userMessage  = $this->appendInMemory($conversation, $message);

        $attachment = (new WittyAttachment())->setOriginalFilename('leads.csv')->setKind(WittyAttachment::KIND_SPREADSHEET);
        $this->setId($attachment, 42);
        $userMessage->addAttachment($attachment);

        $manager = $this->manager();

        $dtos = $manager->toMessages($conversation);

        $this->assertCount(1, $dtos);
        $this->assertSame(
            "Voici la liste\n\n[Piece jointe : leads.csv (spreadsheet, id=42)]",
            $dtos[0]->content,
        );
        $this->assertSame('Voici la liste', $userMessage->getContent(), 'Le contenu persiste ne doit jamais etre modifie.');
    }

    public function testToMessagesLeavesMessagesWithoutAttachmentsUntouched(): void
    {
        $conversation = new WittyConversation();
        $this->appendInMemory($conversation, Message::user('Bonjour'));

        $dtos = $this->manager()->toMessages($conversation);

        $this->assertSame('Bonjour', $dtos[0]->content);
    }

    public function testDisplayTranscriptExposesAttachmentsWithTheirAssetUrl(): void
    {
        $conversation = new WittyConversation();
        $userMessage  = $this->appendInMemory($conversation, Message::user('Voici une image'));

        $attachment = (new WittyAttachment())->setOriginalFilename('logo.png')->setKind(WittyAttachment::KIND_IMAGE);
        $userMessage->addAttachment($attachment);

        $attachmentManager = $this->createMock(AttachmentManager::class);
        $attachmentManager->method('assetUrl')->with($attachment)->willReturn('/asset/1:logo');

        $manager    = $this->manager($attachmentManager);
        $transcript = $manager->toDisplayTranscript($conversation);

        $this->assertSame('Voici une image', $transcript[0]['text'], 'La bulle affichee ne doit pas contenir la note technique.');
        $this->assertSame([[
            'id'        => null,
            'filename'  => 'logo.png',
            'kind'      => WittyAttachment::KIND_IMAGE,
            'asset_url' => '/asset/1:logo',
        ]], $transcript[0]['attachments']);
    }

    private function appendInMemory(WittyConversation $conversation, Message $dto): WittyMessage
    {
        $message = WittyMessage::fromDto($dto, $conversation->getMessages()->count());
        $conversation->addMessage($message);

        return $message;
    }

    private function manager(?AttachmentManager $attachmentManager = null): ConversationManager
    {
        return new ConversationManager(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(UserHelper::class),
            $attachmentManager ?? $this->createMock(AttachmentManager::class),
        );
    }

    private function setId(WittyAttachment $attachment, int $id): void
    {
        $property = new \ReflectionProperty($attachment, 'id');
        $property->setAccessible(true);
        $property->setValue($attachment, $id);
    }
}
