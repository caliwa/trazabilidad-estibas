<?php

namespace App\Http\Controllers;

use App\Domain\Pallets\PalletQr;
use App\Models\Pallet;
use App\Models\PalletLabelPrint;
use Illuminate\Contracts\View\View;

/**
 * Printable label for a print already recorded by PrintLabel. Viewing it
 * changes nothing; opening it again shows the same print number, so a
 * reprint always goes through PrintLabel and its reason.
 */
class PalletLabelController extends Controller
{
    public function show(Pallet $pallet, int $print): View
    {
        $record = PalletLabelPrint::query()->where('pallet_id', $pallet->id)->where('print_number', $print)->firstOrFail();
        $pallet->loadMissing('item:id,description');

        return view('labels.pallet', [
            'pallet' => $pallet,
            'print' => $record,
            'qrSvg' => PalletQr::svg($pallet),
            'widthMm' => config()->integer('traceability.label.width_mm'),
            'heightMm' => config()->integer('traceability.label.height_mm'),
        ]);
    }
}
