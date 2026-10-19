<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a sensor or reader by its bearer token. Only the sha256
 * of the token is stored; an inactive device is refused like an unknown one.
 */
class AuthenticateDevice
{
    public const ATTRIBUTE = 'device';

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $device = $token === null || $token === ''
            ? null
            : Device::query()->where('token_hash', Device::hashToken($token))->where('active', true)->first();

        if ($device === null) {
            return response()->json(['message' => 'Dispositivo no autorizado.'], 401);
        }

        // Write at most once a minute, not on every box.
        if ($device->last_seen_at === null || $device->last_seen_at->lessThan(now()->subMinute())) {
            Device::query()->whereKey($device->id)->update(['last_seen_at' => now()]);
        }

        $request->attributes->set(self::ATTRIBUTE, $device);

        return $next($request);
    }
}
