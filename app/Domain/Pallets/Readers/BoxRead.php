<?php

namespace App\Domain\Pallets\Readers;

/**
 * One line from a fixed reader and what happened to it.
 */
final readonly class BoxRead
{
    public function __construct(
        public BoxReadOutcome $outcome,
        public string $message,
    ) {}
}
