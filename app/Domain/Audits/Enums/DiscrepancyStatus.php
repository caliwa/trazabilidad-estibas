<?php

namespace App\Domain\Audits\Enums;

enum DiscrepancyStatus: string
{
    case Open = 'ABIERTA';
    case InReview = 'EN_REVISION';
    case Resolved = 'RESUELTA';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Abierta',
            self::InReview => 'En revisión',
            self::Resolved => 'Resuelta',
        };
    }
}
