<?php

namespace App\Domain\BoxLabels;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Carbon\CarbonImmutable;

/**
 * ZPL II for a 100 × 80 mm box label on a 203 dpi Zebra (8 dots per mm,
 * 800 × 640 dots). Every label carries its own box code (in the QR and
 * printed under it), so a batch is one ^XA…^XZ format per box.
 *
 *   ┌────────────────────────────────────────┐
 *   │ OF 33128                     ┌──────┐  │
 *   │ PT1350023                    │  QR  │  │
 *   │ GELATINA OLIMPICA ...        │      │  │
 *   │ Lote / Vence / Contenido     └──────┘  │
 *   │────────────────────────────────────────│
 *   │         ║│║│║ ITF-14 ║│║│║            │
 *   │         1 20 00013 50023 6             │
 *   └────────────────────────────────────────┘
 *
 * Text on the left in the same order as the QR, the QR on the right sized
 * to its content so it never leaves the label, and the EAN-14 (GTIN-14) as
 * ITF-14 across the bottom, apart from the QR so a scanner never confuses
 * them.
 */
final class BoxLabelZpl
{
    public const WIDTH_DOTS = 800;

    public const HEIGHT_DOTS = 640;

    /** Room for the QR on the right, in dots. */
    private const QR_BOX = 250;

    /** ITF-14 narrow bar: 4 dots = 0.5 mm, the GS1 minimum is 0.495 mm. */
    private const ITF_NARROW = 4;

    private const ITF_HEIGHT = 140;

    /**
     * One label per box code, in order.
     *
     * @param  list<string>  $boxCodes
     */
    public static function renderBoxes(BoxLabelPayload $payload, CarbonImmutable $productionDate, array $boxCodes): string
    {
        return implode('', array_map(fn (string $code) => self::render($payload->withBox($code), $productionDate, 1), $boxCodes));
    }

    public static function render(BoxLabelPayload $payload, CarbonImmutable $productionDate, int $copies): string
    {
        $f = fn (string $text) => self::field($text);
        [$order, $item, $description, $lot, $expires, $content] = array_slice($payload->lines(), 0, 6);
        $qrX = self::WIDTH_DOTS - 30 - self::QR_BOX;
        $textWidth = $qrX - 50;

        $lines = [
            '^XA',
            '^CI28',
            '^PW'.self::WIDTH_DOTS,
            '^LL'.self::HEIGHT_DOTS,
            '^LH0,0',
            // Left: what a person reads, in the order of the QR.
            '^FO30,28^A0N,58,58^FD'.$f($order).'^FS',
            '^FO30,96^A0N,40,40^FD'.$f($item).'^FS',
            '^FO30,146^FB'.$textWidth.',2,4,L^A0N,30,30^FD'.$f($description).'^FS',
            '^FO30,222^A0N,34,34^FD'.$f($lot).'^FS',
            '^FO30,266^A0N,34,34^FD'.$f($expires).'^FS',
            '^FO30,310^FB'.$textWidth.',2,4,L^A0N,30,30^FD'.$f($content).'^FS',
            // Right: the QR. Line breaks travel as hex (^FH) so each field is one line on the reader.
            '^FO'.$qrX.',28^BQN,2,'.self::qrMagnification($payload->encode()).'^FH_^FDMA,'.self::hexField($payload->encode()).'^FS',
            // The box's own code under the QR, for a person to read.
            ...($payload->boxCode === null ? [] : ['^FO'.$qrX.',286^FB'.self::QR_BOX.',1,0,C^A0N,26,26^FDCaja '.$f($payload->boxCode).'^FS']),
            // Separator, then the ITF-14 centred with its digits (no bearer bars, as the business asked).
            '^FO30,388^GB740,3,3^FS',
        ];

        if (Itf14::isValid($payload->barcode)) {
            $narrow = self::ITF_NARROW;
            // 14 digits of 2 wide + 3 narrow, start (4 narrow) and stop (1 wide + 2 narrow).
            $symbol = (int) round((14 * (2 * Itf14::RATIO + 3) + 4 + Itf14::RATIO + 2) * $narrow);
            // Centred, the quiet zones (10 narrow bars each side) are the white around it.
            $x = intdiv(self::WIDTH_DOTS - $symbol, 2);
            // Check digit already in the data (last N): the printer must not add another.
            $lines[] = '^FO'.$x.',412^BY'.$narrow.','.Itf14::RATIO.'^B2N,'.self::ITF_HEIGHT.',N,N,N^FD'.$payload->barcode.'^FS';
            $lines[] = '^FO0,'.(412 + self::ITF_HEIGHT + 10).'^FB'.self::WIDTH_DOTS.',1,0,C^A0N,30,30^FD'.Itf14::readable($payload->barcode).'^FS';
        }

        $lines[] = '^FO30,'.(self::HEIGHT_DOTS - 26).'^A0N,20,20^FDProd. '.$productionDate->format('d/m/Y').'^FS';
        $lines[] = '^PQ'.$copies.',0,1,Y';
        $lines[] = '^XZ';

        return implode("\n", $lines)."\n";
    }

    /**
     * Dots per module so the QR (with its version for this text) fits its box.
     */
    public static function qrMagnification(string $content): int
    {
        $modules = Encoder::encode($content, ErrorCorrectionLevel::M(), 'UTF-8')->getVersion()->getDimensionForVersion();

        return max(3, min(8, intdiv(self::QR_BOX, $modules)));
    }

    /**
     * ^ and ~ start ZPL commands; they can never reach a field as text.
     */
    private static function field(string $text): string
    {
        return trim(str_replace(['^', '~', "\r", "\n"], ' ', $text));
    }

    /**
     * Field data for ^FH_: the underscore itself and line breaks as hex.
     */
    private static function hexField(string $text): string
    {
        return str_replace(['_', "\n"], ['_5F', '_0A'], str_replace(['^', '~', "\r"], ' ', $text));
    }
}
