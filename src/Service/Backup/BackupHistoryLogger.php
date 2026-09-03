<?php

namespace App\Service\Backup;

use Psr\Log\LoggerInterface;

/**
 * Historique des sauvegardes/restaurations (cahier des charges —
 * FONCTIONNALITÉ 16 §14/§16) : un fichier JSONL append-only dans le dossier
 * de sauvegarde lui-même — pas de nouvelle table ("aucune migration
 * inutile", §21) — lu par le tableau de bord admin et par les tests.
 *
 * N'écrit jamais de secret : {@see BackupRecord} ne transporte que des
 * métadonnées (type, statut, taille, empreinte, chemin, durée, motif
 * d'échec le cas échéant).
 */
class BackupHistoryLogger
{
    public function __construct(
        private readonly string $backupPath,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function record(BackupRecord $record): void
    {
        if (!is_dir($this->backupPath)) {
            mkdir($this->backupPath, 0770, true);
        }

        $line = json_encode($record->toArray(), \JSON_UNESCAPED_SLASHES).\PHP_EOL;
        file_put_contents($this->historyFile(), $line, \FILE_APPEND | \LOCK_EX);

        $context = $record->toArray();
        if ($record->success) {
            $this->logger->info(sprintf('[backup] %s %s (%s) réussi(e).', $record->kind, $record->type, $record->tier), $context);
        } else {
            $this->logger->error(sprintf('[backup] %s %s (%s) échoué(e) : %s', $record->kind, $record->type, $record->tier, $record->error), $context);
        }
    }

    /**
     * @return array<int, array<string, mixed>> les entrées les plus récentes en premier
     */
    public function recent(int $limit = 20): array
    {
        if (!is_file($this->historyFile())) {
            return [];
        }

        $lines = array_filter(explode(\PHP_EOL, file_get_contents($this->historyFile())));
        $entries = array_map(static fn (string $line) => json_decode($line, true), $lines);

        return array_slice(array_reverse($entries), 0, $limit);
    }

    private function historyFile(): string
    {
        return $this->backupPath.'/history.jsonl';
    }
}
