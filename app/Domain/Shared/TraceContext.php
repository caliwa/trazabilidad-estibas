<?php

namespace App\Domain\Shared;

use App\Models\Device;
use App\Models\User;

/**
 * Who is acting, from where, and under which correlation id. Built by the
 * entry point (Livewire page, API controller, job, command) and passed to
 * the use case, so the use case never reads the request directly.
 */
final readonly class TraceContext
{
    private function __construct(
        public ActorType $actorType,
        public ?int $userId,
        public ?int $deviceId,
        public Origin $origin,
        public string $correlationId,
        public ?string $ipAddress,
    ) {}

    public static function forUser(User $user, Origin $origin, string $correlationId, ?string $ip = null): self
    {
        return new self(ActorType::User, $user->id, null, $origin, $correlationId, $ip);
    }

    public static function forDevice(Device $device, string $correlationId, ?string $ip = null, Origin $origin = Origin::Api): self
    {
        return new self(ActorType::Device, null, $device->id, $origin, $correlationId, $ip);
    }

    public static function system(Origin $origin, string $correlationId): self
    {
        return new self(ActorType::System, null, null, $origin, $correlationId, null);
    }
}
