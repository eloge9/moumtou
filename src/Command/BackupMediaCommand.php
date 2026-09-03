<?php

namespace App\Command;

use App\Service\Backup\BackupAdminAlerter;
use App\Service\Backup\BackupHistoryLogger;
use App\Service\Backup\MediaBackupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sauvegarde des fichiers utilisateurs réellement stockés par MOUMTOU
 * (cahier des charges — FONCTIONNALITÉ 16 §4) : photos de projets, avatars,
 * logos d'établissements et de recruteurs. Voir {@see MediaBackupService}
 * pour ce qui est explicitement exclu (preuves externes GitHub/YouTube/etc.).
 */
#[AsCommand(
    name: 'app:backup:media',
    description: 'Sauvegarde les fichiers utilisateurs de MOUMTOU (photos, avatars, logos) dans une archive compressée.',
)]
class BackupMediaCommand extends Command
{
    public function __construct(
        private readonly MediaBackupService $backupService,
        private readonly BackupHistoryLogger $historyLogger,
        private readonly BackupAdminAlerter $alerter,
        private readonly bool $backupEnabled,
        private readonly int $retentionDaily,
        private readonly int $retentionWeekly,
        private readonly int $retentionMonthly,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('tier', InputArgument::OPTIONAL, 'daily | weekly | monthly | manual', 'manual')
            ->addOption('force', null, null, 'Effectue la sauvegarde même si BACKUP_ENABLED=false.')
            ->addOption('dry-run', null, null, 'N\'exécute rien, affiche seulement ce qui serait fait.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tier = (string) $input->getArgument('tier');
        if (!\in_array($tier, ['daily', 'weekly', 'monthly', 'manual'], true)) {
            $io->error('Palier invalide : daily, weekly, monthly ou manual.');

            return Command::FAILURE;
        }

        if (!$this->backupEnabled && !$input->getOption('force')) {
            $io->note('BACKUP_ENABLED=false : sauvegarde ignorée (utilisez --force pour outrepasser).');

            return Command::SUCCESS;
        }

        if ($input->getOption('dry-run')) {
            $io->note(sprintf('[dry-run] Archiverait les médias MOUMTOU (palier "%s", rétention %d) sans rien exécuter.', $tier, $this->retentionFor($tier)));

            return Command::SUCCESS;
        }

        $record = $this->backupService->backup($tier, $this->retentionFor($tier));
        $this->historyLogger->record($record);

        if (!$record->success) {
            $this->alerter->alertOnFailure($record);
            $io->error('BACKUP FAILED'.\PHP_EOL.'Reason: '.$record->error);

            return Command::FAILURE;
        }

        $io->success(sprintf(
            "BACKUP SUCCESS\nMedia: OK\nIntegrity: OK\nSize: %s\nChecksum: %s\nFile: %s",
            $this->humanSize($record->sizeBytes ?? 0),
            $record->checksumSha256,
            $record->path,
        ));

        return Command::SUCCESS;
    }

    private function retentionFor(string $tier): int
    {
        return match ($tier) {
            'daily' => $this->retentionDaily,
            'weekly' => $this->retentionWeekly,
            'monthly' => $this->retentionMonthly,
            default => 0,
        };
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' o';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' Ko';
        }

        return round($bytes / (1024 * 1024), 1).' Mo';
    }
}
