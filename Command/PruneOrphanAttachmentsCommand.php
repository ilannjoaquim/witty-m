<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Command;

use MauticPlugin\WittyBundle\Service\Attachment\AttachmentManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Supprime les pieces jointes uploadees puis jamais envoyees (l'utilisateur
 * a joint un fichier, puis ferme l'onglet ou change d'avis) : fichier sur
 * disque (ou Asset Mautic), et ligne en base.
 *
 * A executer periodiquement via le cron systeme (comme
 * witty:meet:reconcile-attendance, Mautic n'a pas de planificateur interne) :
 *   php bin/console witty:attachments:prune-orphans
 */
#[AsCommand(name: 'witty:attachments:prune-orphans', description: 'Supprime les pieces jointes du chat jamais rattachees a une conversation.')]
class PruneOrphanAttachmentsCommand extends Command
{
    /** Delai de grace avant suppression : laisse le temps a l'utilisateur d'envoyer son message. */
    private const GRACE_PERIOD_HOURS = 24;

    public function __construct(private AttachmentManager $attachments)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $before = new \DateTimeImmutable(sprintf('-%d hours', self::GRACE_PERIOD_HOURS));
        $count  = $this->attachments->pruneOrphans($before);

        $output->writeln(sprintf('%d piece(s) jointe(s) orpheline(s) supprimee(s).', $count));

        return Command::SUCCESS;
    }
}
