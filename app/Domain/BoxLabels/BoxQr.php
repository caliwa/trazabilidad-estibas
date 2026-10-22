<?php

namespace App\Domain\BoxLabels;

/**
 * What the box QR carries: a version prefix and the box's opaque token, and
 * nothing else, like the pallet QR (PalletQr). Reading it only tells the
 * server which box it is; the order, product, lot and expiration come from
 * the database. The human-readable text is printed on the label beside it.
 */
final class BoxQr
{
    public const PREFIX = 'TRZB:';

    public static function payload(string $token): string
    {
        return self::PREFIX.$token;
    }

    public static function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * The token inside a scanned value, or null when it is not a box QR.
     * Tolerates what keyboard-like readers do: spaces or a line break around
     * it, the ":" typed as another symbol on a different keyboard layout,
     * capitals with caps lock on.
     */
    public static function tokenFrom(string $scanned): ?string
    {
        if (preg_match('/^\s*TRZB\X([0-9a-f]{32})\s*$/iu', $scanned, $m) !== 1) {
            return null;
        }

        return strtolower($m[1]);
    }
}
