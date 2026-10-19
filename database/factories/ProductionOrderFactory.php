<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\ProductionOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionOrder>
 */
class ProductionOrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ref' => 'OP-'.fake()->unique()->numerify('######'),
            'sap_doc_entry' => fake()->unique()->numberBetween(1, 9_999_999),
            'item_id' => Item::factory(),
            'sap_status' => null,
            'is_open' => true,
            'synced_at' => now(),
        ];
    }
}
