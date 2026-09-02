<?php

namespace App\Enum;

enum CommentStatus: string
{
    case VISIBLE = 'visible';
    case MASQUE = 'masque';
    case SUPPRIME = 'supprime';

    public function label(): string
    {
        return match ($this) {
            self::VISIBLE => 'Visible',
            self::MASQUE => 'Masqué',
            self::SUPPRIME => 'Supprimé',
        };
    }
}
