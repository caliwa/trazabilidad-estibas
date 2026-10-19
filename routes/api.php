<?php

use App\Http\Controllers\Api\ScanEventController;
use App\Http\Middleware\AuthenticateDevice;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware([AuthenticateDevice::class, 'throttle:scan-events'])->group(function () {
    Route::post('scan-events', [ScanEventController::class, 'store'])->name('api.scan-events.store');
});
