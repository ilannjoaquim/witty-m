<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<WittyConversation>
 */
class WittyConversationRepository extends CommonRepository
{
    /**
     * Les conversations d'un utilisateur, la plus recemment modifiee en tete.
     *
     * @return WittyConversation[]
     */
    public function findForUser(int $userId, int $limit = 30): array
    {
        return $this->createQueryBuilder('c')
            ->where('IDENTITY(c.user) = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('c.dateModified', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Chargee avec ses messages : evite N+1 requetes a la reprise d'un fil.
     */
    public function findOneForUser(int $id, int $userId): ?WittyConversation
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.messages', 'm')
            ->addSelect('m')
            ->where('c.id = :id')
            ->andWhere('IDENTITY(c.user) = :userId')
            ->setParameter('id', $id)
            ->setParameter('userId', $userId)
            ->orderBy('m.sequence', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Recherche plein texte (LIKE) dans les messages user/assistant des
     * conversations de l'utilisateur. Les messages d'outils sont exclus : ils
     * ne veulent rien dire pour l'utilisateur qui cherche un souvenir precis.
     *
     * @return array<int, array{conversation_id:int, title:string, role:string, content:string, dateAdded: \DateTimeInterface}>
     */
    public function searchMessages(int $userId, string $term, int $limit = 25): array
    {
        // Echappe les jokers LIKE : un terme contenant '%' ou '_' ne doit pas
        // se comporter comme un motif, l'utilisateur cherche un texte litteral.
        $escaped = addcslashes($term, '%_\\');

        return $this->createQueryBuilder('c')
            ->select('c.id AS conversation_id', 'c.title AS title', 'm.role AS role', 'm.content AS content', 'm.dateAdded AS dateAdded')
            ->join('c.messages', 'm')
            ->where('IDENTITY(c.user) = :userId')
            ->andWhere("m.role IN ('user', 'assistant')")
            ->andWhere('m.content LIKE :term')
            ->setParameter('userId', $userId)
            ->setParameter('term', '%'.$escaped.'%')
            ->orderBy('m.dateAdded', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
