<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Service\Job\Handlers\DeduplicateContactsJobHandler;
use MauticPlugin\WittyBundle\Service\Lead\DuplicateContactGroupFinder;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

/**
 * Detecte les contacts en double (meme definition qu'Mautic lui-meme, cf.
 * DuplicateContactGroupFinder) et lance leur fusion en arriere-plan.
 *
 * Repond au manque signale en session : search_contacts ne permet de
 * parcourir un gros volume que page par page, aucun moyen d'agir en masse
 * sur un ensemble filtre. Celui-ci ne demande AUCUN parametre de recherche :
 * il s'appuie sur la meme definition de doublon que Mautic (les champs
 * coches "identifiant unique" dans Reglages > Champs, object=lead
 * uniquement -- jamais un critere invente ici), jamais sur une recherche
 * texte construite a la volee.
 */
class StartDeduplicateContactsTool extends AbstractTool
{
    public function __construct(
        private DuplicateContactGroupFinder $finder,
        private EntityManagerInterface $em,
        private UserHelper $userHelper,
    ) {
    }

    public function getName(): string
    {
        return 'start_deduplicate_contacts';
    }

    public function getDescription(): string
    {
        return 'Detecte tous les contacts en double (meme email, ou tout autre champ contact coche '
            .'"identifiant unique" dans Mautic > Reglages > Champs) et lance leur fusion en arriere-plan : '
            .'le contact le plus ancien de chaque groupe survit, les autres sont fusionnes dedans via le '
            .'meme mecanisme que la fusion manuelle de Mautic (historique, points, tags, IP recuperes sur '
            .'le survivant, rien n est perdu, journalise dans un enregistrement de fusion) puis supprimes. '
            .'Aucun parametre de recherche : detecte TOUS les doublons de la base, pas un sous-ensemble. '
            .'Ne renvoie jamais de resultat directement pour un volume important : un job_id a suivre via '
            .'check_bulk_job, puis list_bulk_job_items une fois status=completed.';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        // deleteother, pas deleteown : la fusion peut toucher des contacts
        // qui n appartiennent pas a l utilisateur courant (deux contacts en
        // double n ont aucune raison d avoir le meme proprietaire assigne).
        return 'lead:leads:deleteother';
    }

    public function getObjectType(): ?string
    {
        return 'contact';
    }

    public function getSchema(): array
    {
        return $this->schema([], []);
    }

    public function execute(array $arguments): array
    {
        $groups = $this->finder->find();

        if ([] === $groups) {
            return $this->ok([
                'groups_found'    => 0,
                'contacts_merged' => 0,
                'message'         => 'Aucun doublon detecte (base sur les champs contact identifiant unique de Mautic).',
            ]);
        }

        $totalLosers = array_sum(array_map(static fn (array $g): int => count($g['ids']) - 1, $groups));
        $fieldsUsed  = array_values(array_unique(array_map(static fn (array $g): string => $g['field'], $groups)));

        if (true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'groups_found'       => count($groups),
                'contacts_a_fusionner' => $totalLosers,
                'champs_identifiant_unique_utilises' => $fieldsUsed,
                'regle'             => 'Le contact le plus ancien de chaque groupe survit ; les autres sont fusionnes dedans (historique/points/tags conserves) puis supprimes.',
                'irreversible'      => true,
                'avertissement'     => sprintf('%d groupe(s) de doublons, %d contact(s) qui seront fusionnes puis supprimes.', count($groups), $totalLosers),
            ]);
        }

        $job = (new WittyBackgroundJob())
            ->setType(DeduplicateContactsJobHandler::TYPE)
            ->setCreatedBy($this->userHelper->getUser())
            ->setLabel(sprintf('Deduplication de contacts (%d groupes, %d a fusionner)', count($groups), $totalLosers))
            ->setParams(['groups' => array_map(static fn (array $g): array => $g['ids'], $groups)])
            ->setTotalItems($totalLosers);

        $this->em->persist($job);
        $this->em->flush();

        return $this->ok([
            'job_id'  => $job->getId(),
            'message' => sprintf(
                'Job #%d lance en arriere-plan (%d groupe(s), %d contact(s) a fusionner). '
                .'Utilise check_bulk_job(job_id=%d) pour suivre la progression, puis list_bulk_job_items pour '
                .'le detail par contact.',
                $job->getId(),
                count($groups),
                $totalLosers,
                $job->getId(),
            ),
        ]);
    }
}
