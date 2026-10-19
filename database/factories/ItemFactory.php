<?php

namespace Database\Factories;

use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'PT-'.fake()->unique()->numerify('#####'),
            'description' => fake()->words(3, true),
            'inventory_uom' => 'UND',
            'active' => true,
            'synced_at' => now(),
        ];
    }
}
