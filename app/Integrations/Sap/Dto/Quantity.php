<?php

namespace App\Integrations\Sap\Dto;

use InvalidArgumentException;

/**
 * Exact decimal quantity with up to 6 decimals, kept as a string so it is
 * never rounded through a float on its way to SAP.
 */
final readonly class Quantity
{
    public string $value;

    public function __construct(string $value)
    {
        if (preg_match('/^\d{1,12}(\.\d{1,6})?$/', $value) !== 1 || (float) $value <= 0) {
            throw new InvalidArgumentException("Cantidad inválida para SAP: [{$value}].");
        }

        $this->value = $value;
    }

    /**
     * boxes × units per box, computed on integers scaled by 10^6.
     */
    public static function ofBoxes(int $boxes, string $unitsPerBox): self
    {
        if ($boxes <= 0) {
            throw new InvalidArgumentException('El número de cajas debe ser positivo.');
        }

        $factor = new self($unitsPerBox);
        [$int, $dec] = array_pad(explode('.', $factor->value), 2, '');
        $micro = (int) $int * 1_000_000 + (int) str_pad($dec, 6, '0');

        // Checked before multiplying: past PHP_INT_MAX the product would silently become a float.
        // 10^18 micro-units is 10^12 units, the most numeric(18,6) holds.
        if ($micro > intdiv(1_000_000_000_000_000_000 - 1, $boxes)) {
            throw new InvalidArgumentException('La cantidad supera el máximo permitido.');
        }

        $total = $boxes * $micro;

        $whole = intdiv($total, 1_000_000);
        $fraction = rtrim(str_pad((string) ($total % 1_000_000), 6, '0', STR_PAD_LEFT), '0');

        return new self($fraction === '' ? (string) $whole : "{$whole}.{$fraction}");
    }

    /**
     * The quantity in millionths, exact.
     */
    public function micro(): int
    {
        [$int, $dec] = array_pad(explode('.', $this->value), 2, '');

        return (int) $int * 1_000_000 + (int) str_pad($dec, 6, '0');
    }

    /**
     * Without padding zeros: "12.000000" reads "12", "2.500000" reads "2.5".
     */
    public function display(): string
    {
        [$int, $dec] = array_pad(explode('.', $this->value), 2, '');
        $int = ltrim($int, '0') === '' ? '0' : ltrim($int, '0');
        $dec = rtrim($dec, '0');

        return $dec === '' ? $int : "{$int}.{$dec}";
    }

    public function equals(self $other): bool
    {
        return self::normalize($this->value) === self::normalize($other->value);
    }

    private static function normalize(string $value): string
    {
        [$int, $dec] = array_pad(explode('.', $value), 2, '');

        return ltrim($int, '0').'.'.rtrim($dec, '0');
    }
}
