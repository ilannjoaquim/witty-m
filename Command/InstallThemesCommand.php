<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Command;

use MauticPlugin\WittyBundle\Service\Theme\ThemeInstaller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Redeploiement manuel des themes.
 *
 *   php bin/console witty:themes:install                 ecrase les themes deja presents
 *   php bin/console witty:themes:install --keep-existing  n installe que les manquants
 */
#[AsCommand(
    name: 'witty:themes:install',
    description: 'Copie les themes livres par Witty dans le dossier themes/ de Mautic.',
)]
class InstallThemesCommand extends Command
{
    public function __construct(private ThemeInstaller $themeInstaller)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'keep-existing',
            null,
            InputOption::VALUE_NONE,
            'Ne pas ecraser un theme deja present dans themes/.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $report = $this->themeInstaller->install(!$input->getOption('keep-existing'));

        if ([] === $report) {
            $io->warning('Aucun theme a installer.');

            return Command::SUCCESS;
        }

        $failed = false;
        $rows   = [];

        foreach ($report as $theme => $state) {
            $failed = $failed || str_starts_with($state, 'echec');
            $rows[] = [$theme, $state];
        }

        $io->table(['Theme', 'Etat'], $rows);

        if ($failed) {
            $io->error('Au moins un theme n a pas pu etre installe.');

            return Command::FAILURE;
        }

        $io->success('Themes disponibles a la creation d un email.');

        return Command::SUCCESS;
    }
}
