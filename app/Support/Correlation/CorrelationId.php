<?php

namespace App\Support\Correlation;

use Illuminate\Support\Str;

/**
 * Correlation id of the current request, job or command. One per unit of
 * work; it ends up in trace_events, in structured logs and in the
 * X-Correlation-Id response header.
 */
class CorrelationId
{
    private ?string $value = null;

    public function get(): string
    {
        return $this->value ??= (string) Str::uuid();
    }

    public function set(string $value): void
    {
        $this->value = Str::isUuid($value) ? strtolower($value) : (string) Str::uuid();
    }
}
