<?php

use App\Http\Controllers\Api\ScanEventController;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\AuthenticateDevice;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware([AuthenticateDevice::class, 'throttle:scan-events'])->group(function () {
    Route::post('scan-events', [ScanEventController::class, 'store'])->name('api.scan-events.store');
});

Route::get('health', HealthController::class)->middleware('throttle:60,1')->name('health');
