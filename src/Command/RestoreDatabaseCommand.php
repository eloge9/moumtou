<?php

namespace App\Command;

use App\Service\Backup\BackupHistoryLogger;
use App\Service\Backup\DatabaseBackupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Restauration de la base MySQL (cahier des charges — FONCTIONNALITÉ 16
 * §9/§10) : CLI uniquement, aucune route HTTP n'appelle cette commande —
 * un utilisateur normal ne peut donc jamais y accéder depuis l'application
 * publique (cahier §10). L'opération remplace le contenu de la base
 * courante : `--force` est obligatoire pour éviter une exécution accidentelle.
 *
 * ⚠️ Ne jamais exécuter contre une base de production pour "tester" la
 * restauration (cahier §12) — utilisez un environnement de test/staging.
 */
#[AsCommand(
    name: 'app:restore:database',
    description: 'Restaure un dump MySQL MOUMTOU (.sql ou .sql.gz) dans la base actuellement configurée.',
)]
class RestoreDatabaseCommand extends Command
{
    public function __construct(
        private readonly DatabaseBackupService $backupService,
        private readonly BackupHistoryLogger $historyLogger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('archive', InputArgument::REQUIRED, 'Chemin du dump à restaurer (.sql ou .sql.gz).')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirme la restauration (obligatoire — écrase la base actuelle).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $archive = (string) $input->getArgument('archive');

        if (!$input->getOption('force')) {
            $io->error('Cette opération remplace le contenu de la base actuelle. Relancez avec --force pour confirmer (jamais sur la production sans nécessité réelle — cahier §12).');

            return Command::FAILURE;
        }

        $io->warning(sprintf('Restauration de "%s" en cours…', $archive));

        $record = $this->backupService->restore($archive);
        $this->historyLogger->record($record);

        if (!$record->success) {
            $io->error('RESTORE FAILED'.\PHP_EOL.'Reason: '.$record->error);

            return Command::FAILURE;
        }

        $io->success('RESTORE SUCCESS'.\PHP_EOL.'Database: OK');

        return Command::SUCCESS;
    }
}
