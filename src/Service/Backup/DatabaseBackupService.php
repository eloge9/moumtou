<?php

namespace App\Service\Backup;

use Doctrine\DBAL\Connection;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Sauvegarde de la base MySQL (cahier des charges — FONCTIONNALITÉ 16 §3) :
 * dump `mysqldump`, compression, empreinte, vérification et rétention par
 * palier. Logique extraite de l'ancienne `BackupDatabaseCommand`
 * (FONCTIONNALITÉ 15) pour être réutilisable depuis la sauvegarde complète
 * (§5) sans dupliquer le code.
 */
class DatabaseBackupService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $backupPath,
        private readonly string $environment,
    ) {
    }

    /**
     * @throws \RuntimeException si le backup ne peut pas être réalisé
     */
    public function backup(string $tier, int $retentionCount): BackupRecord
    {
        $startedAt = new \DateTimeImmutable();
        $params = $this->connection->getParams();

        if ('pdo_mysql' !== ($params['driver'] ?? null) && !str_starts_with((string) ($params['driver'] ?? ''), 'mysqli')) {
            return $this->failure('database', $tier, $startedAt, 'Base de données non-MySQL : cette fonctionnalité ne prend en charge que MySQL (cahier des charges).');
        }

        $mysqldump = (new ExecutableFinder())->find('mysqldump');
        if (!$mysqldump) {
            return $this->failure('database', $tier, $startedAt, 'mysqldump est introuvable sur ce serveur (PATH).');
        }

        $filesystem = new Filesystem();
        if (!$filesystem->exists($this->backupPath)) {
            $filesystem->mkdir($this->backupPath, 0770);
        }

        $dbName = $params['dbname'] ?? $params['path'] ?? 'moumtou';
        // Suffixe aléatoire : évite toute collision de nom si deux
        // sauvegardes démarrent dans la même seconde (cahier §3 — horodatage
        // fiable), et empêche un artefact de cache interne de PHP Phar
        // lorsque deux archives portent temporairement un chemin identique.
        $filename = sprintf('moumtou-database-%s-%s-%s-%s.sql', $tier, $this->environment, $startedAt->format('Ymd-His'), bin2hex(random_bytes(3)));
        $rawPath = $this->backupPath.'/'.$filename;

        $command = [
            $mysqldump,
            '--host='.($params['host'] ?? '127.0.0.1'),
            '--port='.($params['port'] ?? 3306),
            '--user='.($params['user'] ?? 'root'),
            '--single-transaction', // cohérence d'une base active (cahier §21), sans verrou global
            '--routines',
            '--result-file='.$rawPath,
            $dbName,
        ];

        $process = new Process($command, null, ['MYSQL_PWD' => $params['password'] ?? '']);
        $process->setTimeout(600);
        $process->run();

        if (!$process->isSuccessful()) {
            @unlink($rawPath);

            return $this->failure('database', $tier, $startedAt, 'Échec de mysqldump : '.$this->firstLine($process->getErrorOutput()));
        }

        $compressedPath = $rawPath.'.gz';
        if (!$this->compress($rawPath, $compressedPath)) {
            return $this->failure('database', $tier, $startedAt, 'Échec de la compression du dump.');
        }
        $filesystem->remove($rawPath);

        if (!is_file($compressedPath) || 0 === filesize($compressedPath)) {
            return $this->failure('database', $tier, $startedAt, 'Le fichier de sauvegarde est absent ou vide après compression.');
        }

        $checksum = hash_file('sha256', $compressedPath);
        file_put_contents($compressedPath.'.sha256', $checksum.'  '.basename($compressedPath).\PHP_EOL);

        $this->pruneRetention($filesystem, 'database', $tier, $retentionCount);

        return new BackupRecord(
            type: 'database',
            tier: $tier,
            kind: 'backup',
            success: true,
            startedAt: $startedAt,
            finishedAt: new \DateTimeImmutable(),
            path: $compressedPath,
            sizeBytes: filesize($compressedPath),
            checksumSha256: $checksum,
        );
    }

    /**
     * Restaure un dump (compressé ou non) dans la base courante. Réservé à
     * la CLI (cahier §10) : aucune route HTTP n'appelle cette méthode.
     */
    public function restore(string $archivePath): BackupRecord
    {
        $startedAt = new \DateTimeImmutable();

        if (!is_file($archivePath)) {
            return $this->failure('database', 'manual', $startedAt, 'Fichier introuvable : '.$archivePath, 'restore');
        }

        $checksumFile = $archivePath.'.sha256';
        if (is_file($checksumFile)) {
            $expected = trim(explode(' ', (string) file_get_contents($checksumFile))[0]);
            if (!hash_equals($expected, (string) hash_file('sha256', $archivePath))) {
                return $this->failure('database', 'manual', $startedAt, 'Empreinte SHA-256 invalide : le fichier a peut-être été altéré.', 'restore');
            }
        }

        $mysql = (new ExecutableFinder())->find('mysql');
        if (!$mysql) {
            return $this->failure('database', 'manual', $startedAt, 'mysql (client) est introuvable sur ce serveur (PATH).', 'restore');
        }

        $sqlPath = $archivePath;
        $isTemp = false;
        if (str_ends_with($archivePath, '.gz')) {
            $sqlPath = sys_get_temp_dir().'/'.uniqid('moumtou-restore-', true).'.sql';
            if (!$this->decompress($archivePath, $sqlPath)) {
                return $this->failure('database', 'manual', $startedAt, 'Échec de la décompression du dump.', 'restore');
            }
            $isTemp = true;
        }

        $params = $this->connection->getParams();
        $dbName = $params['dbname'] ?? $params['path'] ?? 'moumtou';

        $process = new Process([
            $mysql,
            '--host='.($params['host'] ?? '127.0.0.1'),
            '--port='.($params['port'] ?? 3306),
            '--user='.($params['user'] ?? 'root'),
            $dbName,
        ], null, ['MYSQL_PWD' => $params['password'] ?? '']);
        $process->setInput(fopen($sqlPath, 'r'));
        $process->setTimeout(900);
        $process->run();

        if ($isTemp) {
            @unlink($sqlPath);
        }

        if (!$process->isSuccessful()) {
            return $this->failure('database', 'manual', $startedAt, 'Échec de la restauration : '.$this->firstLine($process->getErrorOutput()), 'restore');
        }

        return new BackupRecord(
            type: 'database',
            tier: 'manual',
            kind: 'restore',
            success: true,
            startedAt: $startedAt,
            finishedAt: new \DateTimeImmutable(),
            path: $archivePath,
        );
    }

    private function compress(string $source, string $destination): bool
    {
        $in = fopen($source, 'rb');
        $out = gzopen($destination, 'wb9');
        if (!$in || !$out) {
            return false;
        }
        while (!feof($in)) {
            gzwrite($out, fread($in, 1024 * 512));
        }
        fclose($in);
        gzclose($out);

        return true;
    }

    private function decompress(string $source, string $destination): bool
    {
        $in = gzopen($source, 'rb');
        $out = fopen($destination, 'wb');
        if (!$in || !$out) {
            return false;
        }
        while (!gzeof($in)) {
            fwrite($out, gzread($in, 1024 * 512));
        }
        gzclose($in);
        fclose($out);

        return true;
    }

    /**
     * Ne conserve que les `$retentionCount` sauvegardes les plus récentes
     * d'un palier donné (cahier §6) — les sauvegardes "manual" (ad hoc,
     * déclenchées explicitement) ne sont jamais purgées automatiquement.
     */
    private function pruneRetention(Filesystem $filesystem, string $type, string $tier, int $retentionCount): void
    {
        if ('manual' === $tier || $retentionCount <= 0) {
            return;
        }

        $files = glob($this->backupPath.'/moumtou-'.$type.'-'.$tier.'-*.sql.gz') ?: [];
        usort($files, static fn (string $a, string $b) => filemtime($b) <=> filemtime($a));

        foreach (\array_slice($files, $retentionCount) as $old) {
            $filesystem->remove($old);
            $filesystem->remove($old.'.sha256');
        }
    }

    private function failure(string $type, string $tier, \DateTimeImmutable $startedAt, string $error, string $kind = 'backup'): BackupRecord
    {
        return new BackupRecord(
            type: $type,
            tier: $tier,
            kind: $kind,
            success: false,
            startedAt: $startedAt,
            finishedAt: new \DateTimeImmutable(),
            error: $error,
        );
    }

    private function firstLine(string $text): string
    {
        return trim(explode("\n", trim($text))[0] ?? '');
    }
}
