<?php

namespace App\Domain\Operations;

enum AlertLevel: string
{
    case Warning = 'warning';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Warning => 'Atención',
            self::Critical => 'Crítica',
        };
    }
}
