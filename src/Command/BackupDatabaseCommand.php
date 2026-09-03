<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Sauvegarde de la base MySQL (cahier des charges — FONCTIONNALITÉ 15
 * §38/§39) : aucun système n'existait, celui-ci reste volontairement
 * minimal — un dump `mysqldump` horodaté avec rétention, pas une
 * infrastructure de sauvegarde complète (agent, service tiers…), qui serait
 * disproportionnée pour ce projet.
 *
 * Stratégie :
 * - Fréquence recommandée : quotidienne (tâche planifiée, hors dépôt — cf.
 *   {@see \App\Command\SendDefenseRemindersCommand} pour le même principe) :
 *     0 3 * * *  php /chemin/vers/moumtou/bin/console app:backup:database
 * - Emplacement : %kernel.project_dir%/var/backups/ par défaut (hors
 *   public/, donc jamais servi par le serveur web) — à copier vers un
 *   stockage hors serveur (autre machine, stockage objet…) par l'opérateur
 *   d'infrastructure ; ce dépôt de code ne peut pas décider de ce transfert.
 * - Rétention : les dumps de plus de `--keep-days` jours (14 par défaut)
 *   sont supprimés à chaque exécution.
 * - Restauration :
 *     mysql -u <utilisateur> -p <base> < var/backups/moumtou-prod-AAAAMMJJ-HHMMSS.sql
 * - Vérification : `--dry-run` liste ce qui serait fait sans rien exécuter ;
 *   une restauration doit être testée périodiquement sur un environnement
 *   de test, jamais directement en production (cahier §39).
 *
 * Le mot de passe de connexion n'apparaît jamais dans la commande ni dans
 * les journaux : il est transmis à mysqldump via la variable d'environnement
 * MYSQL_PWD du sous-processus (cahier §32 : pas de secret dans les logs, ni
 * visible par un `ps aux` sur un serveur partagé).
 */
#[AsCommand(
    name: 'app:backup:database',
    description: 'Sauvegarde la base MySQL de MOUMTOU dans un fichier horodaté, avec rétention.',
)]
class BackupDatabaseCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $projectDir,
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('output-dir', null, InputOption::VALUE_REQUIRED, 'Dossier de destination des dumps.', $this->projectDir.'/var/backups')
            ->addOption('keep-days', null, InputOption::VALUE_REQUIRED, 'Nombre de jours de rétention.', '14')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'N\'exécute rien, affiche seulement ce qui serait fait.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $params = $this->connection->getParams();
        if ('pdo_mysql' !== ($params['driver'] ?? null) && !str_starts_with((string) ($params['driver'] ?? ''), 'mysqli')) {
            $io->error('Cette commande ne prend en charge que MySQL (cahier des charges : "si MySQL est utilisé, conserve MySQL").');

            return Command::FAILURE;
        }

        $outputDir = rtrim((string) $input->getOption('output-dir'), '/\\');
        $keepDays = max(1, (int) $input->getOption('keep-days'));
        $dryRun = (bool) $input->getOption('dry-run');

        $dbName = $params['dbname'] ?? $params['path'] ?? 'moumtou';
        $filename = sprintf('moumtou-%s-%s.sql', $this->environment, (new \DateTimeImmutable())->format('Ymd-His'));
        $targetPath = $outputDir.'/'.$filename;

        if ($dryRun) {
            $io->note(sprintf('[dry-run] Créerait %s (base "%s") et supprimerait les dumps de plus de %d jour(s) dans %s.', $targetPath, $dbName, $keepDays, $outputDir));

            return Command::SUCCESS;
        }

        $mysqldump = (new ExecutableFinder())->find('mysqldump');
        if (!$mysqldump) {
            $io->error('mysqldump est introuvable sur ce serveur. Installez le client MySQL ou adaptez le PATH.');

            return Command::FAILURE;
        }

        $filesystem = new Filesystem();

        if (!$filesystem->exists($outputDir)) {
            $filesystem->mkdir($outputDir, 0770);
        }

        $command = [
            $mysqldump,
            '--host='.($params['host'] ?? '127.0.0.1'),
            '--port='.($params['port'] ?? 3306),
            '--user='.($params['user'] ?? 'root'),
            '--single-transaction',
            '--routines',
            '--result-file='.$targetPath,
            $dbName,
        ];

        $process = new Process($command, null, ['MYSQL_PWD' => $params['password'] ?? '']);
        $process->setTimeout(600);
        $process->run();

        if (!$process->isSuccessful()) {
            $io->error('Échec de mysqldump : '.$process->getErrorOutput());

            return Command::FAILURE;
        }

        $io->success(sprintf('Sauvegarde créée : %s (%s).', $targetPath, $this->humanSize((int) filesize($targetPath))));

        $removed = $this->pruneOldBackups($filesystem, $outputDir, $keepDays);
        if ($removed > 0) {
            $io->writeln(sprintf('%d ancien(s) dump(s) supprimé(s) (rétention : %d jours).', $removed, $keepDays));
        }

        return Command::SUCCESS;
    }

    private function pruneOldBackups(Filesystem $filesystem, string $outputDir, int $keepDays): int
    {
        $threshold = (new \DateTimeImmutable())->modify(sprintf('-%d days', $keepDays))->getTimestamp();
        $removed = 0;

        foreach (glob($outputDir.'/moumtou-*.sql') ?: [] as $file) {
            if (filemtime($file) < $threshold) {
                $filesystem->remove($file);
                ++$removed;
            }
        }

        return $removed;
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
