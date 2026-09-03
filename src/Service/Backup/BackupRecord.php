<?php

namespace App\Service\Backup;

/**
 * Résultat d'une opération de sauvegarde/restauration (cahier des charges —
 * FONCTIONNALITÉ 16 §8/§14) — objet de valeur simple, sérialisé tel quel
 * dans l'historique JSONL par {@see BackupHistoryLogger}.
 */
final class BackupRecord
{
    public function __construct(
        public readonly string $type,
        public readonly string $tier,
        public readonly string $kind,
        public readonly bool $success,
        public readonly \DateTimeImmutable $startedAt,
        public readonly \DateTimeImmutable $finishedAt,
        public readonly ?string $path = null,
        public readonly ?int $sizeBytes = null,
        public readonly ?string $checksumSha256 = null,
        public readonly ?string $error = null,
    ) {
    }

    public function durationSeconds(): float
    {
        return round($this->finishedAt->format('U.u') - $this->startedAt->format('U.u'), 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'tier' => $this->tier,
            'kind' => $this->kind,
            'status' => $this->success ? 'success' : 'failed',
            'startedAt' => $this->startedAt->format(\DATE_ATOM),
            'finishedAt' => $this->finishedAt->format(\DATE_ATOM),
            'durationSeconds' => $this->durationSeconds(),
            'path' => $this->path,
            'sizeBytes' => $this->sizeBytes,
            'checksumSha256' => $this->checksumSha256,
            'error' => $this->error,
        ];
    }
}
