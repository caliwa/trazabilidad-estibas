<?php

namespace Tests\Unit\Domain;

use App\Integrations\Sap\Dto\Quantity;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QuantityTest extends TestCase
{
    /**
     * @return array<string, array{int, string, string}>
     */
    public static function conversions(): array
    {
        return [
            'entero' => [40, '12', '480'],
            'decimales exactos' => [3, '0.1', '0.3'],
            'seis decimales' => [7, '1.333333', '9.333331'],
            'ceros a la derecha' => [4, '2.500000', '10'],
        ];
    }

    #[DataProvider('conversions')]
    public function test_boxes_convert_exactly_without_floats(int $boxes, string $factor, string $expected): void
    {
        $this->assertSame($expected, Quantity::ofBoxes($boxes, $factor)->value);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalid(): array
    {
        return [
            'cero' => ['0'],
            'negativo' => ['-1'],
            'siete decimales' => ['1.0000001'],
            'notación científica' => ['1e3'],
            'vacío' => [''],
            'coma decimal' => ['1,5'],
        ];
    }

    #[DataProvider('invalid')]
    public function test_invalid_quantities_are_refused(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Quantity($value);
    }

    public function test_zero_boxes_and_overflow_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Quantity::ofBoxes(0, '12');
    }

    public function test_a_product_beyond_the_column_is_refused_instead_of_turning_into_a_float(): void
    {
        $this->expectExceptionMessage('supera el máximo');
        Quantity::ofBoxes(2_000_000, '999999999.999999');
    }

    public function test_equality_ignores_padding(): void
    {
        $this->assertTrue((new Quantity('10'))->equals(new Quantity('10.000000')));
        $this->assertFalse((new Quantity('10'))->equals(new Quantity('10.000001')));
    }
}
