<?php

namespace Database\Factories;

use App\Models\BoxConversionFactor;
use App\Models\BoxLabelBatch;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A recorded set of box labels. Tests that only need the lot's dates use
 * this instead of going through GenerateBoxLabels.
 *
 * @extends Factory<BoxLabelBatch>
 */
class BoxLabelBatchFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'production_order_id' => ProductionOrder::factory(),
            'item_id' => fn (array $a) => ProductionOrder::query()->whereKey($a['production_order_id'])->sole()->item_id,
            'box_conversion_factor_id' => fn (array $a) => BoxConversionFactor::query()->where('item_id', $a['item_id'])->value('id')
                ?? BoxConversionFactor::factory()->create(['item_id' => $a['item_id']])->id,
            'production_order_ref' => fn (array $a) => ProductionOrder::query()->whereKey($a['production_order_id'])->sole()->ref,
            'item_code' => 'PT-TEST',
            'item_description' => 'PRODUCTO DE PRUEBA',
            'lot_code' => 'L-TEST',
            'production_date' => '2026-10-20',
            'expiration_date' => '2028-10-20',
            'units_per_box' => '12.000000',
            'uom_name' => 'UNIDAD',
            'barcode' => '12000013500233',
            'labels' => 10,
            'qr_payload' => 'TEST',
            'zpl' => '^XA^XZ',
            'generated_by' => User::factory(),
            'generated_at' => now(),
        ];
    }
}
