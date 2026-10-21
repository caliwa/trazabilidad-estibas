<?php

namespace App\Domain\Orders;

/**
 * What a sync of released orders from SAP did.
 */
final readonly class SyncResult
{
    /**
     * @param  array<string, string>  $problems  order ref => why it could not be updated
     */
    public function __construct(
        public int $released,
        public array $problems = [],
        public ?string $error = null,
    ) {}
}
