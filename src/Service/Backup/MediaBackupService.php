<?php

namespace App\Service\Backup;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Sauvegarde des fichiers utilisateurs réellement stockés par MOUMTOU
 * (cahier des charges — FONCTIONNALITÉ 16 §4) : photos de profils, photos
 * de projets, logos d'établissements et de recruteurs. N'inclut jamais les
 * preuves externes (GitHub, YouTube, sites, mémoires en lien) : MOUMTOU n'en
 * possède que l'URL, jamais le fichier (cahier §4, "ne télécharge pas
 * inutilement ces fichiers").
 *
 * Utilise `PharData` (extension `phar`, standard PHP) plutôt qu'un binaire
 * `tar` externe : portable entre l'environnement de développement (Windows)
 * et un serveur de production (Linux) sans dépendance supplémentaire.
 */
class MediaBackupService
{
    /** @var array<string, string> libellé => chemin absolu */
    private array $directories;

    public function __construct(
        private readonly string $backupPath,
        private readonly string $environment,
        string $projectUploadsDirectory,
        string $avatarUploadsDirectory,
        string $institutionLogoUploadsDirectory,
        string $recruiterLogoUploadsDirectory,
    ) {
        $this->directories = [
            'projects' => $projectUploadsDirectory,
            'avatars' => $avatarUploadsDirectory,
            'institutions' => $institutionLogoUploadsDirectory,
            'recruiters' => $recruiterLogoUploadsDirectory,
        ];
    }

    public function backup(string $tier, int $retentionCount): BackupRecord
    {
        $startedAt = new \DateTimeImmutable();
        $filesystem = new Filesystem();

        if (!$filesystem->exists($this->backupPath)) {
            $filesystem->mkdir($this->backupPath, 0770);
        }

        $existingDirectories = array_filter($this->directories, static fn (string $dir) => is_dir($dir) && (new \FilesystemIterator($dir))->valid());
        if (!$existingDirectories) {
            return $this->failure($tier, $startedAt, 'Aucun média à sauvegarder pour le moment (dossiers vides ou absents).');
        }

        // Suffixe aléatoire : évite toute collision de nom (deux sauvegardes
        // démarrées dans la même seconde) et un artefact de cache interne de
        // PHP Phar si un chemin d'archive était temporairement réutilisé.
        $filename = sprintf('moumtou-media-%s-%s-%s-%s.tar', $tier, $this->environment, $startedAt->format('Ymd-His'), bin2hex(random_bytes(3)));
        $tarPath = $this->backupPath.'/'.$filename;
        $gzPath = $tarPath.'.gz';

        try {
            // buildFromDirectory() placerait les fichiers à la racine de
            // l'archive sans distinguer leur dossier d'origine : on ajoute
            // donc chaque fichier explicitement avec un préfixe
            // ("projects/…", "avatars/…"…) pour que la restauration sache
            // où replacer chaque type de média.
            $phar = new \PharData($tarPath);
            foreach ($existingDirectories as $label => $dir) {
                $this->addDirectoryWithPrefix($phar, $dir, $label);
            }
            unset($phar);

            if (is_file($gzPath)) {
                @unlink($gzPath);
            }
            $pharForCompression = new \PharData($tarPath);
            $pharForCompression->compress(\Phar::GZ);
            unset($pharForCompression);
            $filesystem->remove($tarPath);
        } catch (\Throwable $e) {
            @unlink($tarPath);

            return $this->failure($tier, $startedAt, 'Échec de l\'archivage des médias : '.$e->getMessage());
        }

        if (!is_file($gzPath) || 0 === filesize($gzPath)) {
            return $this->failure($tier, $startedAt, 'L\'archive des médias est absente ou vide après compression.');
        }

        $checksum = hash_file('sha256', $gzPath);
        file_put_contents($gzPath.'.sha256', $checksum.'  '.basename($gzPath).\PHP_EOL);

        $this->pruneRetention($filesystem, $tier, $retentionCount);

        return new BackupRecord(
            type: 'media',
            tier: $tier,
            kind: 'backup',
            success: true,
            startedAt: $startedAt,
            finishedAt: new \DateTimeImmutable(),
            path: $gzPath,
            sizeBytes: filesize($gzPath),
            checksumSha256: $checksum,
        );
    }

    /**
     * Restaure une archive médias vers `$targetDirectory` (par défaut le
     * dossier `public/uploads` réel). Réservé à la CLI (cahier §10).
     */
    public function restore(string $archivePath, string $targetDirectory): BackupRecord
    {
        $startedAt = new \DateTimeImmutable();

        if (!is_file($archivePath)) {
            return $this->failure('manual', $startedAt, 'Fichier introuvable : '.$archivePath, 'restore');
        }

        $checksumFile = $archivePath.'.sha256';
        if (is_file($checksumFile)) {
            $expected = trim(explode(' ', (string) file_get_contents($checksumFile))[0]);
            if (!hash_equals($expected, (string) hash_file('sha256', $archivePath))) {
                return $this->failure('manual', $startedAt, 'Empreinte SHA-256 invalide : l\'archive a peut-être été altérée.', 'restore');
            }
        }

        $filesystem = new Filesystem();
        if (!$filesystem->exists($targetDirectory)) {
            $filesystem->mkdir($targetDirectory, 0775);
        }

        // L'extraction peut échouer de façon transitoire ("copying contents
        // failed") sous Windows lorsqu'un antivirus verrouille brièvement un
        // fichier venant d'être écrit ; on retente une fois après une courte
        // pause avant de considérer l'échec comme réel.
        $lastError = null;
        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            try {
                $phar = new \PharData($archivePath);
                $phar->extractTo($targetDirectory, null, true);
                unset($phar);
                $lastError = null;

                break;
            } catch (\Throwable $e) {
                unset($phar);
                $lastError = $e;
                if ($attempt < 2) {
                    usleep(300_000);
                }
            }
        }
        if (null !== $lastError) {
            return $this->failure('manual', $startedAt, 'Échec de l\'extraction : '.$lastError->getMessage(), 'restore');
        }

        return new BackupRecord(
            type: 'media',
            tier: 'manual',
            kind: 'restore',
            success: true,
            startedAt: $startedAt,
            finishedAt: new \DateTimeImmutable(),
            path: $archivePath,
        );
    }

    private function addDirectoryWithPrefix(\PharData $phar, string $dir, string $prefix): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile()) {
                $relative = $prefix.'/'.substr($file->getPathname(), \strlen($dir) + 1);
                $phar->addFile($file->getPathname(), str_replace('\\', '/', $relative));
            }
        }
    }

    private function pruneRetention(Filesystem $filesystem, string $tier, int $retentionCount): void
    {
        if ('manual' === $tier || $retentionCount <= 0) {
            return;
        }

        $files = glob($this->backupPath.'/moumtou-media-'.$tier.'-*.tar.gz') ?: [];
        usort($files, static fn (string $a, string $b) => filemtime($b) <=> filemtime($a));

        foreach (\array_slice($files, $retentionCount) as $old) {
            $filesystem->remove($old);
            $filesystem->remove($old.'.sha256');
        }
    }

    private function failure(string $tier, \DateTimeImmutable $startedAt, string $error, string $kind = 'backup'): BackupRecord
    {
        return new BackupRecord(
            type: 'media',
            tier: $tier,
            kind: $kind,
            success: false,
            startedAt: $startedAt,
            finishedAt: new \DateTimeImmutable(),
            error: $error,
        );
    }
}
