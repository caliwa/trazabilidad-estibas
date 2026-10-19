<?php

namespace Database\Factories;

use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Models\ProductionOrder;
use App\Models\ProductionReceipt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductionReceipt>
 */
class ProductionReceiptFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $payload = ['lines' => []];

        return [
            'request_uuid' => (string) Str::uuid(),
            'production_order_id' => ProductionOrder::factory(),
            'status' => SapDocumentStatus::Requested,
            'payload' => $payload,
            'payload_hash' => hash('sha256', (string) json_encode($payload)),
            'requested_by' => User::factory(),
            'requested_at' => now(),
        ];
    }
}
