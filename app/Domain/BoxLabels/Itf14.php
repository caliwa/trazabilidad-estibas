<?php

namespace App\Domain\BoxLabels;

/**
 * EAN-14 (GTIN-14) printed as ITF-14, the GS1 symbology for corrugated
 * boxes (Interleaved 2 of 5). Check digit and the bar pattern for the
 * on-screen preview; the Zebra draws its own from ^B2.
 *
 * Printed without bearer bars, as the business asked. GS1 recommends them
 * on corrugated board against partial reads; the quiet zones stay, those
 * are indispensable.
 */
final class Itf14
{
    /** Narrow (n) and wide (w) elements per digit. */
    private const DIGITS = ['nnwwn', 'wnnnw', 'nwnnw', 'wwnnn', 'nnwnw', 'wnwnn', 'nwwnn', 'nnnww', 'wnnwn', 'nwnwn'];

    /** Wide to narrow ratio. GS1 allows 2.25 to 3. */
    public const RATIO = 2.5;

    public static function checkDigit(string $data): int
    {
        $sum = 0;
        foreach (array_reverse(str_split($data)) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10;
    }

    public static function isValid(string $code): bool
    {
        return preg_match('/^\d{14}$/', $code) === 1 && self::checkDigit(substr($code, 0, 13)) === (int) $code[13];
    }

    /**
     * Human readable form, as printed under the bars: 1 20 00013 50023 6.
     */
    public static function readable(string $code): string
    {
        return $code[0].' '.substr($code, 1, 2).' '.substr($code, 3, 5).' '.substr($code, 8, 5).' '.$code[13];
    }

    /**
     * Elements from left to right as [isBar, isWide], start and stop included.
     *
     * @return list<array{bool, bool}>
     */
    public static function elements(string $code): array
    {
        // Start: narrow bar, narrow space, narrow bar, narrow space.
        $elements = [[true, false], [false, false], [true, false], [false, false]];

        foreach (str_split($code, 2) as $pair) {
            $bars = self::DIGITS[(int) $pair[0]];
            $spaces = self::DIGITS[(int) $pair[1]];
            for ($i = 0; $i < 5; $i++) {
                $elements[] = [true, $bars[$i] === 'w'];
                $elements[] = [false, $spaces[$i] === 'w'];
            }
        }

        // Stop: wide bar, narrow space, narrow bar.
        return [...$elements, [true, true], [false, false], [true, false]];
    }

    /**
     * SVG with quiet zones and the digits underneath. Use an even narrow
     * width so the wide bars (2.5 ×) land on whole pixels.
     */
    public static function svg(string $code, int $narrow = 2, int $height = 70): string
    {
        $wide = $narrow * self::RATIO;
        $quiet = 10 * $narrow;
        $x = $quiet;
        $rects = '';

        foreach (self::elements($code) as [$isBar, $isWide]) {
            $w = $isWide ? $wide : $narrow;
            if ($isBar) {
                $rects .= sprintf('<rect x="%s" y="0" width="%s" height="%d"/>', $x, $w, $height);
            }
            $x += $w;
        }

        $width = $x + $quiet;
        $font = 9 * $narrow;
        $total = $height + $font + 3 * $narrow;

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%s" height="%d" viewBox="0 0 %s %d" shape-rendering="crispEdges" role="img" aria-label="Código de barras ITF-14 %s"><rect width="100%%" height="100%%" fill="#fff"/><g fill="#000">%s</g><text x="%s" y="%d" font-family="monospace" font-size="%d" text-anchor="middle">%s</text></svg>',
            $width, $total, $width, $total, $code, $rects, $width / 2, $height + $font + $narrow, $font, self::readable($code),
        );
    }
}
