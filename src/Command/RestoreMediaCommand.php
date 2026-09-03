<?php

namespace App\Command;

use App\Service\Backup\BackupHistoryLogger;
use App\Service\Backup\MediaBackupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Restauration des fichiers utilisateurs (cahier des charges —
 * FONCTIONNALITÉ 16 §9/§10) : CLI uniquement. Par défaut, restaure vers
 * `public/uploads` réel (l'archive contient déjà la même arborescence —
 * "projects/…", "avatars/…"…) après avoir déplacé le contenu actuel dans
 * `var/uploads-backup-<horodatage>/` (hors `public/`, donc jamais servi par
 * le serveur web) pour pouvoir annuler si besoin — sauf `--no-safety-copy`.
 */
#[AsCommand(
    name: 'app:restore:media',
    description: 'Restaure une archive de médias MOUMTOU (.tar.gz) vers public/uploads.',
)]
class RestoreMediaCommand extends Command
{
    public function __construct(
        private readonly MediaBackupService $backupService,
        private readonly BackupHistoryLogger $historyLogger,
        private readonly string $uploadsRootDirectory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('archive', InputArgument::REQUIRED, 'Chemin de l\'archive à restaurer (.tar.gz).')
            ->addOption('target-dir', null, InputOption::VALUE_REQUIRED, 'Dossier de destination (par défaut : public/uploads réel).', null)
            ->addOption('no-safety-copy', null, InputOption::VALUE_NONE, 'Ne pas déplacer le contenu actuel avant restauration.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirme la restauration (obligatoire).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $archive = (string) $input->getArgument('archive');
        $target = (string) ($input->getOption('target-dir') ?: $this->uploadsRootDirectory);

        if (!$input->getOption('force')) {
            $io->error('Cette opération remplace les fichiers du dossier cible. Relancez avec --force pour confirmer.');

            return Command::FAILURE;
        }

        $filesystem = new Filesystem();

        if (!$input->getOption('no-safety-copy') && $filesystem->exists($target) && (new \FilesystemIterator($target))->valid()) {
            $safetyCopy = \dirname($target, 2).'/var/uploads-backup-'.(new \DateTimeImmutable())->format('Ymd-His');
            $filesystem->mirror($target, $safetyCopy);
            $io->note('Copie de sécurité du contenu actuel : '.$safetyCopy);
        }

        $io->warning(sprintf('Restauration de "%s" vers "%s" en cours…', $archive, $target));

        $record = $this->backupService->restore($archive, $target);
        $this->historyLogger->record($record);

        if (!$record->success) {
            $io->error('RESTORE FAILED'.\PHP_EOL.'Reason: '.$record->error);

            return Command::FAILURE;
        }

        $io->success('RESTORE SUCCESS'.\PHP_EOL.'Media: OK');

        return Command::SUCCESS;
    }
}
