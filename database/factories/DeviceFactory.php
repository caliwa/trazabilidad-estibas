<?php

namespace Database\Factories;

use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'SEN-'.Str::upper(Str::random(6)),
            'name' => 'Sensor línea '.fake()->numberBetween(1, 9),
            'kind' => 'SENSOR',
            'token_hash' => Device::hashToken(Str::random(40)),
            'active' => true,
        ];
    }

    public function withToken(string $token): static
    {
        return $this->state(['token_hash' => Device::hashToken($token)]);
    }
}
