<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<WittyAttachment>
 */
class WittyAttachmentRepository extends CommonRepository
{
    /**
     * Scope par utilisateur : un identifiant transmis par le front ne doit
     * jamais permettre de lire le fichier de quelqu'un d'autre, meme avant
     * qu'il ne soit rattache a une conversation (cf. WittyAttachment).
     */
    public function findOneForUser(int $id, int $userId): ?WittyAttachment
    {
        return $this->createQueryBuilder('a')
            ->where('a.id = :id')
            ->andWhere('IDENTITY(a.user) = :userId')
            ->setParameter('id', $id)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Uploads jamais envoyes (l'utilisateur a joint un fichier puis n'a pas
     * valide le message, ou a ferme l'onglet) : ni conversation ni message.
     * Les fichiers "pinned" (bibliotheque Fichiers, cf. WittyAttachment) sont
     * exclus : eux n'ont jamais vocation a etre rattaches a une conversation,
     * les nettoyer serait supprimer un fichier que l'utilisateur a
     * deliberement garde pour plus tard.
     *
     * @return WittyAttachment[]
     */
    public function findOrphans(\DateTimeInterface $before): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.conversation IS NULL')
            ->andWhere('a.pinned = false')
            ->andWhere('a.dateAdded < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->getResult();
    }

    /**
     * Bibliotheque "Fichiers" d'un utilisateur : tout ce qu'il a deja envoye
     * a l'agent, rattache a une conversation ou non, plus recent en premier.
     * `search` filtre sur le nom d'origine (utilise aussi bien par la page
     * Fichiers que par l'outil list_attachments, cf. ListAttachmentsTool).
     *
     * @return WittyAttachment[]
     */
    public function findAllForUser(int $userId, ?string $search = null, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('a')
            ->where('IDENTITY(a.user) = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('a.dateAdded', 'DESC')
            ->setMaxResults($limit);

        if (null !== $search && '' !== trim($search)) {
            // Echappe les jokers LIKE : un terme contenant '%' ou '_' ne doit
            // pas se comporter comme un motif.
            $qb->andWhere('a.originalFilename LIKE :search')
                ->setParameter('search', '%'.addcslashes(trim($search), '%_\\').'%');
        }

        return $qb->getQuery()->getResult();
    }
}
