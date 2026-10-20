<?php

namespace App\Domain\Operations;

/**
 * One active operational problem. Never carries box counts: alerts go to
 * administrators, but the text also ends up in mail and logs.
 */
final readonly class Alert
{
    public function __construct(
        public string $key,
        public AlertLevel $level,
        public string $title,
        public string $detail,
        public int $count,
        public ?string $route = null,
    ) {}
}
