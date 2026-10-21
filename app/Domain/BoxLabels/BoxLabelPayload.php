<?php

namespace App\Domain\BoxLabels;

use Carbon\CarbonImmutable;

/**
 * What the box QR carries: the same lines a person reads on the label,
 * one per line, so any reader (a phone, a keyboard-wedge scanner in
 * Notepad) shows them as they are:
 *
 *   OF 33128
 *   PT1350023
 *   GELATINA OLIMPICA SIN SABOR 30 GR
 *   Lote: 33128294
 *   Vence: 20/10/2028
 *   Contenido: 24 UNIDAD por caja
 *
 * The EAN goes in its own barcode, not in the QR.
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
    ) {}

    public function content(): string
    {
        return trim($this->unitsPerBox.' '.($this->uomName ?? '')).' por caja';
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        return array_map(fn (string $line) => self::clean($line), [
            'OF '.$this->orderRef,
            $this->itemCode,
            $this->itemDescription,
            'Lote: '.$this->lotCode,
            'Vence: '.$this->expirationDate->format('d/m/Y'),
            'Contenido: '.$this->content(),
        ]);
    }

    public function encode(): string
    {
        return implode("\n", $this->lines());
    }

    /**
     * The fields of a scanned box QR, or null when it is not one of ours.
     * Readers may turn the line breaks into CR LF.
     *
     * @return array{order: string, item: string, description: string, lot: string, expiration: string, content: string}|null
     */
    public static function decode(string $scanned): ?array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($scanned)) ?: [];

        if (count($lines) !== 6
            || ! str_starts_with($lines[0], 'OF ')
            || ! str_starts_with($lines[3], 'Lote: ')
            || preg_match('#^Vence: \d{2}/\d{2}/\d{4}$#', $lines[4]) !== 1
            || ! str_starts_with($lines[5], 'Contenido: ')) {
            return null;
        }

        return [
            'order' => substr($lines[0], 3),
            'item' => $lines[1],
            'description' => $lines[2],
            'lot' => substr($lines[3], 6),
            'expiration' => substr($lines[4], 7),
            'content' => substr($lines[5], 11),
        ];
    }

    private static function clean(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }
}
