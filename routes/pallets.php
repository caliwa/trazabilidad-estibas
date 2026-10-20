<?php

use App\Http\Controllers\PalletLabelController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('production', 'pages::production.station')->middleware('can:pallets.operate')->name('production.station');

    Route::livewire('cedi/count', 'pages::cedi.count')->middleware('can:audits.count')->name('cedi.count');
    Route::livewire('discrepancies', 'pages::cedi.discrepancies')->middleware('can:discrepancies.resolve')->name('discrepancies.index');

    Route::livewire('operations', 'pages::operations.index')->middleware('can:ops.alerts')->name('operations.index');
    Route::livewire('transfers', 'pages::transfers.index')->middleware('can:transfers.request')->name('transfers.index');
    Route::livewire('receipts', 'pages::receipts.index')->middleware('can:receipts.request')->name('receipts.index');

    Route::prefix('pallets')->name('pallets.')->group(function () {
        Route::livewire('/', 'pages::pallets.index')->middleware('can:pallets.view')->name('index');
        Route::livewire('rejected-scans', 'pages::pallets.rejected')->middleware('can:pallets.view')->name('rejected');
        Route::livewire('{pallet}', 'pages::pallets.show')->middleware('can:pallets.view')->whereNumber('pallet')->name('show');

        // Whoever can print or reprint can open the printable page of a recorded print.
        Route::get('{pallet}/labels/{print}', [PalletLabelController::class, 'show'])
            ->middleware('can:pallets.label')
            ->whereNumber(['pallet', 'print'])
            ->name('label');
    });
});
