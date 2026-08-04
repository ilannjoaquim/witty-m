<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Conversation;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\WittyBundle\Entity\WittyConversation;
use MauticPlugin\WittyBundle\Entity\WittyConversationRepository;
use MauticPlugin\WittyBundle\Entity\WittyMessage;
use MauticPlugin\WittyBundle\Service\Llm\Dto\Message;
use MauticPlugin\WittyBundle\Service\Llm\Dto\Usage;

/**
 * Proprietaire du transcript. Le navigateur ne transporte plus que l'identifiant
 * de la conversation : l'historique est reconstruit depuis la base a chaque tour,
 * ce qui rend un rechargement de page indolore et rend l'historique auditable.
 *
 * Toutes les lectures sont filtrees par utilisateur : une conversation n'est
 * jamais visible d'un autre compte, meme en devinant son identifiant.
 */
class ConversationManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserHelper $userHelper,
    ) {
    }

    public function getCurrentUser(): ?User
    {
        $user = $this->userHelper->getUser();

        return $user instanceof User && null !== $user->getId() ? $user : null;
    }

    /**
     * Charge la conversation demandee, ou en ouvre une nouvelle.
     *
     * @throws ConversationNotFoundException si l'identifiant ne correspond a
     *                                       aucune conversation de l'utilisateur
     */
    public function resolve(?int $conversationId): WittyConversation
    {
        $user = $this->getCurrentUser();

        if (null !== $conversationId && null !== $user) {
            $conversation = $this->getRepository()->findOneForUser($conversationId, (int) $user->getId());

            if (null === $conversation) {
                throw new ConversationNotFoundException(sprintf('Conversation %d introuvable.', $conversationId));
            }

            return $conversation;
        }

        $conversation = new WittyConversation();
        $conversation->setUser($user);

        return $conversation;
    }

    /**
     * Transcript au format attendu par la boucle de l'agent.
     *
     * @return Message[]
     */
    public function toMessages(WittyConversation $conversation): array
    {
        $messages = [];

        foreach ($conversation->getMessages() as $message) {
            $messages[] = $message->toDto();
        }

        return $messages;
    }

    public function append(WittyConversation $conversation, Message $dto, ?Usage $usage = null): WittyMessage
    {
        $message = WittyMessage::fromDto($dto, $conversation->getMessages()->count());

        if (null !== $usage) {
            $message->setUsage($usage->promptTokens, $usage->completionTokens);
            $conversation->addUsage($usage->promptTokens, $usage->completionTokens);
        }

        $conversation->addMessage($message);

        // Le titre vient du premier message utilisateur : c'est ce qui identifie
        // le fil dans la liste laterale.
        if ('' === $conversation->getTitle() && Message::ROLE_USER === $dto->role) {
            $conversation->setTitle(mb_substr((string) $dto->content, 0, 80));
        }

        return $message;
    }

    public function save(WittyConversation $conversation): void
    {
        $conversation->touch();

        $this->entityManager->persist($conversation);
        $this->entityManager->flush();
    }

    public function delete(WittyConversation $conversation): void
    {
        $this->entityManager->remove($conversation);
        $this->entityManager->flush();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForCurrentUser(int $limit = 30): array
    {
        $user = $this->getCurrentUser();

        if (null === $user) {
            return [];
        }

        return array_map(
            static fn (WittyConversation $c): array => [
                'id'           => $c->getId(),
                'title'        => '' !== $c->getTitle() ? $c->getTitle() : 'Sans titre',
                'dateModified' => $c->getDateModified()->format('c'),
                'tokens'       => $c->getTotalTokens(),
            ],
            $this->getRepository()->findForUser((int) $user->getId(), $limit),
        );
    }

    /**
     * Transcript destine a l'affichage : les messages d'outils sont ecartes,
     * ils ne veulent rien dire pour l'utilisateur.
     *
     * @return array<int, array<string, mixed>>
     */
    public function toDisplayTranscript(WittyConversation $conversation): array
    {
        $transcript = [];

        foreach ($conversation->getMessages() as $message) {
            if (Message::ROLE_TOOL === $message->getRole()) {
                continue;
            }

            $content = (string) $message->getContent();

            if ('' === trim($content)) {
                continue;
            }

            $transcript[] = [
                'role'  => $message->getRole(),
                'text'  => $content,
                'tools' => array_map(
                    static fn (array $call): string => (string) ($call['name'] ?? ''),
                    $message->getToolCalls(),
                ),
            ];
        }

        return $transcript;
    }

    /**
     * Recherche un texte precis dans l'historique de l'utilisateur courant.
     * Renvoie un extrait centre sur la premiere occurrence, pas le message
     * entier : ca reste lisible dans une liste de resultats.
     *
     * @return array<int, array{conversation_id:int, title:string, role:string, snippet:string, dateAdded:string}>
     */
    public function search(string $term, int $limit = 25): array
    {
        $user = $this->getCurrentUser();
        $term = trim($term);

        if (null === $user || '' === $term) {
            return [];
        }

        $rows = $this->getRepository()->searchMessages((int) $user->getId(), $term, $limit);

        return array_map(
            static fn (array $row): array => [
                'conversation_id' => (int) $row['conversation_id'],
                'title'           => '' !== $row['title'] ? $row['title'] : 'Sans titre',
                'role'            => $row['role'],
                'snippet'         => self::snippet((string) $row['content'], $term),
                'dateAdded'       => $row['dateAdded']->format('c'),
            ],
            $rows,
        );
    }

    private static function snippet(string $content, string $term, int $context = 60): string
    {
        $position = mb_stripos($content, $term);

        if (false === $position) {
            return mb_substr($content, 0, $context * 2);
        }

        $start  = max(0, $position - $context);
        $length = mb_strlen($term) + (2 * $context);
        $slice  = mb_substr($content, $start, $length);

        return (0 < $start ? '…' : '').trim($slice).(mb_strlen($content) > $start + $length ? '…' : '');
    }

    private function getRepository(): WittyConversationRepository
    {
        /** @var WittyConversationRepository $repository */
        $repository = $this->entityManager->getRepository(WittyConversation::class);

        return $repository;
    }
}
