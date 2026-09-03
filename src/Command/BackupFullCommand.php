<?php

namespace App\Command;

use App\Service\Backup\BackupAdminAlerter;
use App\Service\Backup\BackupHistoryLogger;
use App\Service\Backup\BackupRecord;
use App\Service\Backup\DatabaseBackupService;
use App\Service\Backup\MediaBackupService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sauvegarde complète MOUMTOU (cahier des charges — FONCTIONNALITÉ 16 §5) :
 * base de données + fichiers utilisateurs + un manifeste de configuration
 * *non sensible* nécessaire à la restauration. N'inclut JAMAIS `.env*`, mots
 * de passe, jetons ou clés (cahier §5) : le manifeste ne contient que des
 * informations déjà publiques ou nécessaires (nom de la base, répertoires
 * archivés, version d'environnement).
 */
#[AsCommand(
    name: 'app:backup:full',
    description: 'Sauvegarde complète de MOUMTOU : base de données + fichiers utilisateurs + manifeste de restauration.',
)]
class BackupFullCommand extends Command
{
    public function __construct(
        private readonly DatabaseBackupService $databaseBackupService,
        private readonly MediaBackupService $mediaBackupService,
        private readonly BackupHistoryLogger $historyLogger,
        private readonly BackupAdminAlerter $alerter,
        private readonly Connection $connection,
        private readonly string $backupPath,
        private readonly string $environment,
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
            ->addOption('force', null, null, 'Effectue la sauvegarde même si BACKUP_ENABLED=false.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $startedAt = new \DateTimeImmutable();

        $tier = (string) $input->getArgument('tier');
        if (!\in_array($tier, ['daily', 'weekly', 'monthly', 'manual'], true)) {
            $io->error('Palier invalide : daily, weekly, monthly ou manual.');

            return Command::FAILURE;
        }

        if (!$this->backupEnabled && !$input->getOption('force')) {
            $io->note('BACKUP_ENABLED=false : sauvegarde ignorée (utilisez --force pour outrepasser).');

            return Command::SUCCESS;
        }

        $retention = match ($tier) {
            'daily' => $this->retentionDaily,
            'weekly' => $this->retentionWeekly,
            'monthly' => $this->retentionMonthly,
            default => 0,
        };

        $io->section('Base de données');
        $databaseRecord = $this->databaseBackupService->backup($tier, $retention);
        $this->historyLogger->record($databaseRecord);
        $io->writeln($databaseRecord->success ? '<info>OK</info>' : '<error>ÉCHEC : '.$databaseRecord->error.'</error>');

        $io->section('Médias');
        $mediaRecord = $this->mediaBackupService->backup($tier, $retention);
        $this->historyLogger->record($mediaRecord);
        $io->writeln($mediaRecord->success ? '<info>OK</info>' : '<error>ÉCHEC : '.$mediaRecord->error.'</error>');

        $manifestPath = null;
        if ($databaseRecord->success) {
            $manifestPath = $this->writeManifest($tier, $startedAt, $databaseRecord, $mediaRecord);
        }

        $overallSuccess = $databaseRecord->success && $mediaRecord->success;
        $summary = new BackupRecord(
            type: 'full',
            tier: $tier,
            kind: 'backup',
            success: $overallSuccess,
            startedAt: $startedAt,
            finishedAt: new \DateTimeImmutable(),
            path: $manifestPath,
            sizeBytes: ($databaseRecord->sizeBytes ?? 0) + ($mediaRecord->sizeBytes ?? 0),
            error: $overallSuccess ? null : trim(($databaseRecord->error ?? '').' '.($mediaRecord->error ?? '')),
        );
        $this->historyLogger->record($summary);

        if (!$overallSuccess) {
            $this->alerter->alertOnFailure($summary);
            $io->error('BACKUP FAILED'.\PHP_EOL.'Reason: '.$summary->error);

            return Command::FAILURE;
        }

        $io->success(sprintf(
            "BACKUP SUCCESS\nDatabase: OK\nMedia: OK\nIntegrity: OK\nSize: %s\nManifest: %s",
            $this->humanSize($summary->sizeBytes ?? 0),
            $manifestPath,
        ));

        return Command::SUCCESS;
    }

    /**
     * Petit fichier JSON à côté des archives, listant ce dont un
     * administrateur a besoin pour restaurer (jamais de secret — cahier §5).
     */
    private function writeManifest(string $tier, \DateTimeImmutable $startedAt, BackupRecord $database, BackupRecord $media): string
    {
        $params = $this->connection->getParams();
        $manifest = [
            'generatedAt' => $startedAt->format(\DATE_ATOM),
            'appEnvironment' => $this->environment,
            'databaseName' => $params['dbname'] ?? $params['path'] ?? null,
            'databaseArchive' => $database->path ? basename($database->path) : null,
            'mediaArchive' => $media->success && $media->path ? basename($media->path) : null,
            'mediaDirectories' => ['projects', 'avatars', 'institutions', 'recruiters'],
            'note' => 'Restaurer nécessite un fichier .env.local complet (DATABASE_URL, APP_SECRET, clés OAuth/mail) — volontairement absent de cette sauvegarde. Voir docs/backup-restore.md.',
        ];

        $manifestPath = sprintf('%s/moumtou-manifest-%s-%s-%s.json', $this->backupPath, $tier, $this->environment, $startedAt->format('Ymd-His'));
        file_put_contents($manifestPath, json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        return $manifestPath;
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' Ko';
        }

        return round($bytes / (1024 * 1024), 1).' Mo';
    }
}
