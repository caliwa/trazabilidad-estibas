<?php

namespace Database\Factories;

use App\Domain\Pallets\Enums\ScanEventStatus;
use App\Models\Device;
use App\Models\PalletScanEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PalletScanEvent>
 */
class PalletScanEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'external_event_id' => (string) Str::uuid(),
            'pallet_id' => null,
            'production_order_ref' => 'OP-000001',
            'item_code' => 'PT-00001',
            'lot_code' => 'L000001',
            'event_occurred_at' => now(),
            'received_at' => now(),
            'processing_status' => ScanEventStatus::Rejected,
            'rejection_reason' => 'ESTANDAR_AUSENTE',
            'payload_hash' => hash('sha256', Str::random()),
        ];
    }
}
