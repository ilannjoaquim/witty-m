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
     *
     * @return WittyAttachment[]
     */
    public function findOrphans(\DateTimeInterface $before): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.conversation IS NULL')
            ->andWhere('a.dateAdded < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->getResult();
    }
}
