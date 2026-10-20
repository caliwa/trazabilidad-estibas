<?php

namespace App\Domain\BoxLabels;

use Carbon\CarbonImmutable;

/**
 * ZPL II for a 100 × 80 mm box label on a 203 dpi Zebra (8 dots per mm,
 * 800 × 640 dots). All the copies of a batch are identical, so the label is
 * sent once with ^PQ and the printer repeats it.
 */
final class BoxLabelZpl
{
    public const WIDTH_DOTS = 800;

    public const HEIGHT_DOTS = 640;

    public static function render(
        BoxLabelPayload $payload,
        string $itemDescription,
        CarbonImmutable $productionDate,
        int $copies,
    ): string {
        $f = fn (string $text) => self::field($text);
        $units = trim($payload->unitsPerBox.' '.($payload->uomName ?? ''));

        $lines = [
            '^XA',
            '^CI28',
            '^PW'.self::WIDTH_DOTS,
            '^LL'.self::HEIGHT_DOTS,
            '^LH0,0',
            // Left column: what a person reads.
            '^FO30,30^A0N,46,46^FDOF '.$f($payload->orderRef).'^FS',
            '^FO30,90^A0N,34,34^FD'.$f($payload->itemCode).'^FS',
            '^FO30,135^FB480,3,4,L^A0N,30,30^FD'.$f($itemDescription).'^FS',
            '^FO30,250^A0N,30,30^FDLote: '.$f($payload->lotCode).'^FS',
            '^FO30,290^A0N,30,30^FDProducción: '.$productionDate->format('d/m/Y').'^FS',
            '^FO30,330^A0N,30,30^FDVence: '.$payload->expirationDate->format('d/m/Y').'^FS',
            '^FO30,370^A0N,30,30^FDContenido: '.$f($units).' por caja^FS',
            // Right column: the QR (model 2, error correction M, 6 dots per module).
            '^FO540,30^BQN,2,6^FDMA,'.$f($payload->encode()).'^FS',
        ];

        // EAN-13 as a barcode too, for whoever scans the box with a retail reader.
        if (preg_match('/^\d{13}$/', $payload->barcode) === 1) {
            $lines[] = '^FO30,440^BY3^BEN,110,Y,N^FD'.substr($payload->barcode, 0, 12).'^FS';
        } else {
            $lines[] = '^FO30,460^A0N,30,30^FDEAN: '.$f($payload->barcode).'^FS';
        }

        $lines[] = '^PQ'.$copies.',0,1,Y';
        $lines[] = '^XZ';

        return implode("\n", $lines)."\n";
    }

    /**
     * ^ and ~ start ZPL commands; they can never reach a field as text.
     */
    private static function field(string $text): string
    {
        return trim(str_replace(['^', '~', "\r", "\n"], ' ', $text));
    }
}
