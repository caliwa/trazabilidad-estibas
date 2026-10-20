<?php

namespace App\Domain\BoxLabels;

use Carbon\CarbonImmutable;

/**
 * What the box QR carries, in a fixed order after a version prefix:
 *
 *   CJ1|<OF>|<product>|<units per box> <unit>|<lot>|<EAN>|<expiration YYYY-MM-DD>
 *
 * Every box of the same order, lot and date carries the same text: the QR
 * says what is in the box, not which box it is.
 */
final class BoxLabelPayload
{
    public const PREFIX = 'CJ1';

    public function __construct(
        public readonly string $orderRef,
        public readonly string $itemCode,
        public readonly string $unitsPerBox,
        public readonly ?string $uomName,
        public readonly string $lotCode,
        public readonly string $barcode,
        public readonly CarbonImmutable $expirationDate,
    ) {}

    public function encode(): string
    {
        $units = trim($this->unitsPerBox.' '.($this->uomName ?? ''));

        return implode('|', array_map(fn (string $field) => str_replace('|', '/', trim($field)), [
            self::PREFIX, $this->orderRef, $this->itemCode, $units, $this->lotCode, $this->barcode, $this->expirationDate->format('Y-m-d'),
        ]));
    }

    /**
     * The fields of a scanned box QR, or null when it is not one of ours.
     *
     * @return array{order: string, item: string, units: string, lot: string, barcode: string, expiration: string}|null
     */
    public static function decode(string $scanned): ?array
    {
        $parts = explode('|', trim($scanned));

        if (count($parts) !== 7 || $parts[0] !== self::PREFIX || preg_match('/^\d{4}-\d{2}-\d{2}$/', $parts[6]) !== 1) {
            return null;
        }

        return ['order' => $parts[1], 'item' => $parts[2], 'units' => $parts[3], 'lot' => $parts[4], 'barcode' => $parts[5], 'expiration' => $parts[6]];
    }
}
