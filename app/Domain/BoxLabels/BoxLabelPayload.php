<?php

namespace App\Domain\BoxLabels;

use App\Integrations\Sap\Dto\Quantity;
use Carbon\CarbonImmutable;

/**
 * The text printed on a box label, one field per line:
 *
 *   OF 33128
 *   PT1350023
 *   GELATINA OLIMPICA SIN SABOR 30 GR
 *   Lote: 33128294
 *   Vence: 20/10/2028
 *   Contenido: 24 UNIDAD por caja
 *   Caja: 33128294-0007
 *
 * It is for people: the QR beside it carries only the box token (BoxQr),
 * and a read takes these fields from the database. The batch keeps the
 * first six lines; each label adds its box code (withBox()).
 */
final class BoxLabelPayload
{
    public function __construct(
        public readonly string $orderRef,
        public readonly string $itemCode,
        public readonly string $itemDescription,
        public readonly string $unitsPerBox,
        public readonly ?string $uomName,
        public readonly string $lotCode,
        public readonly string $barcode,
        public readonly CarbonImmutable $expirationDate,
        public readonly ?string $boxCode = null,
        // Units printed on the label: "24 UND (CAJA)" from the item's sales data (salesUnits()).
        public readonly ?string $unitsLabel = null,
    ) {}

    /**
     * {{ sales_qty_per_pack_unit }} {{ sales_unit }} ({{ sales_packaging_unit }}) of the item, for
     * example "24 UND (CAJA)". Without the quantity from SAP it uses the box's units per box; parts
     * SAP does not have are left out. Null when SAP has neither the unit nor the packaging.
     */
    public static function salesUnits(?string $qtyPerPackUnit, ?string $salesUnit, ?string $packagingUnit, ?string $fallbackQty = null): ?string
    {
        $unit = trim((string) $salesUnit);
        $pack = trim((string) $packagingUnit);

        // Without the unit and the packaging a bare number says nothing: the label keeps its old line.
        if ($unit === '' && $pack === '') {
            return null;
        }

        $qty = $qtyPerPackUnit !== null && is_numeric($qtyPerPackUnit) && (float) $qtyPerPackUnit > 0 ? (new Quantity($qtyPerPackUnit))->display() : $fallbackQty;
        $text = trim(($qty ?? '').' '.$unit);

        if ($pack !== '') {
            $text = trim($text.' ('.$pack.')');
        }

        return $text === '' ? null : $text;
    }

    /** The units line of the label: the sales units, or the units per box as before when there are none. */
    public function unitsText(): string
    {
        return $this->unitsLabel ?? 'CAJA : '.$this->unitsPerBox.' UNIDADES';
    }

    /**
     * The same label with this box's code.
     */
    public function withBox(string $boxCode): self
    {
        return new self($this->orderRef, $this->itemCode, $this->itemDescription, $this->unitsPerBox, $this->uomName, $this->lotCode, $this->barcode, $this->expirationDate, $boxCode, $this->unitsLabel);
    }

    public function content(): string
    {
        return trim($this->unitsPerBox.' '.($this->uomName ?? '')).' por caja';
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        $lines = [
            'OF '.$this->orderRef,
            $this->itemCode,
            $this->itemDescription,
            'Lote: '.$this->lotCode,
            'Vence: '.$this->expirationDate->format('d/m/Y'),
            'Contenido: '.$this->content(),
        ];

        if ($this->boxCode !== null) {
            $lines[] = 'Caja: '.$this->boxCode;
        }

        return array_map(fn (string $line) => self::clean($line), $lines);
    }

    public function encode(): string
    {
        return implode("\n", $this->lines());
    }

    /**
     * The fields of a scanned box QR, or null when it is not one of ours.
     * Readers may turn the line breaks into CR LF.
     *
     * @return array{order: string, item: string, description: string, lot: string, expiration: string, content: string, box: ?string}|null
     */
    public static function decode(string $scanned): ?array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($scanned)) ?: [];

        if (! in_array(count($lines), [6, 7], true)
            || ! str_starts_with($lines[0], 'OF ')
            || ! str_starts_with($lines[3], 'Lote: ')
            || preg_match('#^Vence: \d{2}/\d{2}/\d{4}$#', $lines[4]) !== 1
            || ! str_starts_with($lines[5], 'Contenido: ')
            || (isset($lines[6]) && ! str_starts_with($lines[6], 'Caja: '))) {
            return null;
        }

        return [
            'order' => substr($lines[0], 3),
            'item' => $lines[1],
            'description' => $lines[2],
            'lot' => substr($lines[3], 6),
            'expiration' => substr($lines[4], 7),
            'content' => substr($lines[5], 11),
            'box' => isset($lines[6]) ? substr($lines[6], 6) : null,
        ];
    }

    private static function clean(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }
}
