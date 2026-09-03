<?php

namespace App\Service;

/**
 * Résout un filtre de période pour les tableaux de bord statistiques
 * (cahier des charges — FONCTIONNALITÉ 12 §16) : "aujourd'hui", "7 jours",
 * "30 jours", "cette année". Point unique de cette logique, réutilisé par
 * les tableaux de bord talent, recruteur et administrateur.
 */
class StatsPeriod
{
    public const TODAY = 'today';
    public const DAYS_7 = '7d';
    public const DAYS_30 = '30d';
    public const YEAR = 'year';

    public const CHOICES = [
        self::TODAY => 'Aujourd\'hui',
        self::DAYS_7 => '7 jours',
        self::DAYS_30 => '30 jours',
        self::YEAR => 'Cette année',
    ];

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: string}
     */
    public static function resolve(?string $period): array
    {
        $period = \array_key_exists((string) $period, self::CHOICES) ? $period : self::DAYS_30;
        $now = new \DateTimeImmutable('today 23:59:59');

        $from = match ($period) {
            self::TODAY => new \DateTimeImmutable('today 00:00:00'),
            self::DAYS_7 => new \DateTimeImmutable('-6 days 00:00:00'),
            self::YEAR => new \DateTimeImmutable('first day of january this year 00:00:00'),
            default => new \DateTimeImmutable('-29 days 00:00:00'),
        };

        return [$from, $now, $period];
    }
}
