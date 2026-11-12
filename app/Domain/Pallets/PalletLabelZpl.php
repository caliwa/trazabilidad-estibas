<?php

namespace App\Domain\Pallets;

use App\Domain\BoxLabels\LabelFormatConfig;
use App\Domain\BoxLabels\LotDates;
use App\Models\Pallet;
use App\Models\PalletLabelPrint;
use Carbon\CarbonImmutable;

/**
 * ZPL II para la etiqueta térmica de estiba (pallet) en Zebra ZT411 (203 dpi, 8 dpmm).
 * Formato vertical (portrait) ocupando los 100 × 80 mm (800 × 640 puntos) mediante
 * rotación de 90° (^FWR), permitiendo que la etiqueta se lea verticalmente con:
 *   - Código de estiba destacado arriba (EST-XXXXXXXX)
 *   - Código QR de alta visibilidad en el centro
 *   - Datos completos de Orden, Producto (PT y descripción), Lote, Vencimiento
 *   - Metadatos de impresión y motivo de reimpresión si aplica
 */
final class PalletLabelZpl
{
    public const WIDTH_DOTS = 800;

    public const HEIGHT_DOTS = 640;

    /**
     * Renderiza la etiqueta ZPL para la estiba.
     *
     * @param  array<string, mixed>|null  $overrideConfig
     */
    public static function render(Pallet $pallet, ?PalletLabelPrint $print = null, ?CarbonImmutable $expiration = null, ?array $overrideConfig = null): string
    {
        $cfg = $overrideConfig ?? LabelFormatConfig::pallet();

        if ($pallet->exists && ! $pallet->relationLoaded('item')) {
            $pallet->loadMissing('item:id,description');
        }

        $palletCode = self::field($pallet->pallet_code);
        $qrPayload = PalletQr::payload($pallet);
        $orderRef = self::field($pallet->production_order_ref);
        $itemCode = self::field($pallet->item_code);
        $itemDesc = self::field($pallet->item->description ?? '');
        $lotCode = self::field($pallet->lot_code);

        $expiration = $expiration ?? ($pallet->exists ? LotDates::expiration($pallet->production_order_id, $pallet->lot_code) : null);
        $expirationFormatted = $expiration ? $expiration->format('m/Y') : '—';

        $printNumber = $print !== null ? $print->print_number : max(1, $pallet->label_print_count);
        $isReprint = $printNumber > 1;
        $tz = function_exists('config') && app()->bound('config') ? config('app.timezone', 'America/Bogota') : 'America/Bogota';
        $printDate = ($print !== null ? CarbonImmutable::parse($print->printed_at) : now())->timezone($tz)->format('d/m/Y H:i');
        $reason = $print?->reason ? self::field($print->reason) : null;

        $width = (int) ($cfg['width_dots'] ?? self::WIDTH_DOTS);
        $height = (int) ($cfg['height_dots'] ?? self::HEIGHT_DOTS);
        $speed = (int) ($cfg['print_speed'] ?? LabelFormatConfig::printSpeed());
        $speed = max(2, min(14, $speed));
        $detailsFont = (int) ($cfg['details_font'] ?? 34);
        $textY = (int) ($cfg['text_y'] ?? 40);

        $lines = [
            '^XA',
            '^CI28',
            '^PW'.$width,
            '^LL'.$height,
            '^LS0',
            '^PR'.$speed.','.$speed.','.$speed,
            '^FWR', // Rotación a 90° para orientación vertical
            // 1. Cabecera vertical: Código de la estiba centrado
            '^FO'.($cfg['pallet_code_x'] ?? 740).','.($cfg['pallet_code_y'] ?? 30).'^FB'.($cfg['pallet_code_w'] ?? 580).',1,0,C^A0R,'.($cfg['pallet_code_font_h'] ?? 54).','.($cfg['pallet_code_font_w'] ?? 52).'^FD'.$palletCode.'^FS',
            // 2. Restablecer estado global ^BY y generar Código QR centrado horizontalmente (mag 8 para máxima legibilidad)
            '^BY2,3,10',
            '^FO'.($cfg['qr_x'] ?? 475).','.($cfg['qr_y'] ?? 204).'^BQR,2,'.($cfg['qr_mag'] ?? 8).'^FH_^FDMA,'.self::hexField($qrPayload).'^FS',
            // 3. Línea divisoria superior
            '^FO'.($cfg['div1_x'] ?? 445).',35^GB2,570,2^FS',
            // 4. Datos de la orden y producto
            '^FO'.($cfg['order_x'] ?? 405).','.$textY.'^A0R,'.$detailsFont.','.$detailsFont.'^FDORDEN: '.$orderRef.'^FS',
            '^FO'.($cfg['item_x'] ?? 365).','.$textY.'^A0R,'.$detailsFont.','.$detailsFont.'^FDPRODUCTO: '.$itemCode.'^FS',
        ];

        // Descripción dinámica adaptativa para evitar sobreposiciones en títulos largos:
        // En ZPL con ^FWR el comando ^FB ubica el cursor en la última línea y crece hacia mayor X.
        // Calculamos foDescX para que la Línea 1 comience exactamente en topDescX (325) sin tocar PRODUCTO (365) ni LOTE.
        $descLen = mb_strlen($itemDesc);
        if ($descLen > 75) {
            $descFont = (int) ($cfg['desc_font_extra_long'] ?? 22);
            $descLines = 3;
        } elseif ($descLen > 45) {
            $descFont = (int) ($cfg['desc_font_long'] ?? 24);
            $descLines = 2;
        } elseif ($descLen > 25) {
            $descFont = (int) ($cfg['desc_font'] ?? 26);
            $descLines = 2;
        } else {
            $descFont = (int) ($cfg['desc_font'] ?? 28);
            $descLines = 1;
        }

        $topDescX = (int) ($cfg['desc_x'] ?? 325);
        $lineSpacing = 2;
        $foDescX = $topDescX - (($descLines - 1) * ($descFont + $lineSpacing));
        $lines[] = '^FO'.$foDescX.','.$textY.'^FB560,'.$descLines.','.$lineSpacing.',L^A0R,'.$descFont.','.$descFont.'^FD'.$itemDesc.'^FS';

        // Lote y Vencimiento ubicados dinámicamente debajo de la descripción para evitar colisiones
        $lotX = (int) ($cfg['lot_x'] ?? ($foDescX - 42));
        $venceX = (int) ($cfg['vence_x'] ?? ($lotX - 42));
        $lines[] = '^FO'.$lotX.','.$textY.'^A0R,'.$detailsFont.','.$detailsFont.'^FDLOTE: '.$lotCode.'^FS';
        $lines[] = '^FO'.$venceX.','.$textY.'^A0R,'.$detailsFont.','.$detailsFont.'^FDVENCE: '.$expirationFormatted.'^FS';

        // 5. Línea divisoria inferior
        $div2X = (int) ($cfg['div2_x'] ?? 150);
        $lines[] = '^FO'.$div2X.',35^GB2,570,2^FS';

        // 6. Pie de página: Metadatos de impresión y motivo de reimpresión si aplica
        $footerX = (int) ($cfg['footer_x'] ?? 115);
        $footerFont = (int) ($cfg['footer_font'] ?? 22);
        $metaText = 'Impresion #'.$printNumber.' - '.$printDate;
        if ($isReprint) {
            $lines[] = '^FO'.$footerX.',40^A0R,'.$footerFont.','.$footerFont.'^FD'.$metaText.'^FS';
            $reasonX = $footerX - 28;
            $reasonText = $reason ? 'REIMPRESION: '.mb_substr($reason, 0, 45) : '*** REIMPRESION ***';
            $lines[] = '^FO'.$reasonX.',40^A0R,'.($footerFont + 2).','.($footerFont + 2).'^FD'.$reasonText.'^FS';
        } else {
            $lines[] = '^FO'.$footerX.',40^A0R,'.$footerFont.','.$footerFont.'^FD'.$metaText.'^FS';
        }

        $lines[] = '^PQ1,0,1,Y';
        $lines[] = '^XZ';

        return implode("\n", $lines)."\n";
    }

    private static function field(string $text): string
    {
        return trim(str_replace(['^', '~', "\r", "\n"], ' ', $text));
    }

    private static function hexField(string $text): string
    {
        return str_replace(['_', "\n"], ['_5F', '_0A'], str_replace(['^', '~', "\r"], ' ', $text));
    }
}
