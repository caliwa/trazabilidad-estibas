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

    /**
     * Like decode(), for what a hand reader actually types: the line breaks
     * may arrive as Enter (CR, LF or both), and with a keyboard layout that
     * differs from the reader's the ":" and "/" may come out as other symbols.
     * So the fields are taken by position and pattern, not by exact text: the
     * order number from the first line, the lot as the last word of the
     * fourth line, the expiration as dd?mm?yyyy with any separator.
     *
     * @return array{order: string, item: string, description: string, lot: string, expiration: CarbonImmutable}|null
     */
    public static function read(string $scanned): ?array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', trim($scanned)) ?: []), fn (string $l) => $l !== ''));

        if (count($lines) < 5
            || preg_match('/^OF\D{0,3}(\d{1,20})$/iu', $lines[0], $order) !== 1
            || preg_match('/^Lote.*?(\S+)$/iu', $lines[3], $lot) !== 1
            || preg_match('/(\d{2})\D(\d{2})\D(\d{4})/', $lines[4], $date) !== 1
            || ! checkdate((int) $date[2], (int) $date[1], (int) $date[3])) {
            return null;
        }

        // "Lote: 33128294" → the last word. If the separator came glued to it ("Lote:33128294",
        // "LoteÑ33128294"), drop "Lote" and whatever is not a letter or digit before the code.
        $lotCode = preg_replace('/^(Lote)?[^A-Za-z0-9]*/iu', '', $lot[1]) ?? $lot[1];

        return [
            'order' => $order[1],
            'item' => $lines[1],
            'description' => $lines[2],
            'lot' => $lotCode,
            'expiration' => CarbonImmutable::createFromDate((int) $date[3], (int) $date[2], (int) $date[1])->startOfDay(),
        ];
    }

    private static function clean(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }
}
