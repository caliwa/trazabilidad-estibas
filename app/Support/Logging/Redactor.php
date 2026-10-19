<?php

namespace App\Support\Logging;

/**
 * Removes secrets from arrays before they are logged or stored as context.
 * Keys are matched by name, at any depth.
 */
class Redactor
{
    private const PATTERN = '/pass(word)?|secret|token|cookie|session|authorization|api[_-]?key|credential|b1session|routeid/i';

    public const MASK = '[redactado]';

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::PATTERN, $key) === 1) {
                $data[$key] = self::MASK;
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }
}
