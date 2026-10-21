<?php

namespace App\Support;

/**
 * Exact decimal quantities (up to 6 decimals) for totals and comparisons.
 * Totals can be zero, which a SAP Quantity never is, so they are kept in
 * millionths as integers and printed back as decimal strings.
 */
final class Quantities
{
    public static function toMicro(string|int|float|null $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        $value = (string) $value;
        $negative = str_starts_with($value, '-');
        [$int, $dec] = array_pad(explode('.', ltrim($value, '-')), 2, '');
        $micro = (int) $int * 1_000_000 + (int) str_pad(substr($dec, 0, 6), 6, '0');

        return $negative ? -$micro : $micro;
    }

    public static function fromMicro(int $micro): string
    {
        $sign = $micro < 0 ? '-' : '';
        $micro = abs($micro);
        $fraction = rtrim(str_pad((string) ($micro % 1_000_000), 6, '0', STR_PAD_LEFT), '0');

        return $sign.intdiv($micro, 1_000_000).($fraction === '' ? '' : ".{$fraction}");
    }

    /**
     * For the screen: thousands with dots, decimals with a comma, no padding zeros.
     */
    public static function format(int $micro): string
    {
        $text = self::fromMicro($micro);
        [$int, $dec] = array_pad(explode('.', ltrim($text, '-')), 2, '');

        return ($micro < 0 ? '-' : '').number_format((int) $int, 0, ',', '.').($dec === '' ? '' : ','.$dec);
    }

    /**
     * Boxes needed for a quantity, counting the last one even if not full.
     */
    public static function boxes(int $micro, int $unitsPerBoxMicro): int
    {
        if ($micro <= 0 || $unitsPerBoxMicro <= 0) {
            return 0;
        }

        return intdiv($micro + $unitsPerBoxMicro - 1, $unitsPerBoxMicro);
    }
}
