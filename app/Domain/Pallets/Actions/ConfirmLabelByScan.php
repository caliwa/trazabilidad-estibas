<?php

namespace App\Domain\Pallets\Actions;

use App\Domain\Pallets\Exceptions\PalletRuleViolation;
use App\Domain\Pallets\PalletQr;
use App\Domain\Shared\TraceContext;
use App\Models\Pallet;
use App\Models\User;

/**
 * Confirms the label applied with two reads on the same physical pallet,
 * without picking the pallet first: the QR of the label stuck on it says
 * which pallet it is, and one of its boxes proves the label went on that
 * pallet. The rules are ConfirmLabeled's.
 */
class ConfirmLabelByScan
{
    public function __construct(private readonly ConfirmLabeled $confirm) {}

    public function handle(User $user, string $labelScanned, ?string $boxScanned, TraceContext $ctx): Pallet
    {
        return $this->confirm->handle($user, $this->palletOf($labelScanned)->id, $labelScanned, $ctx, $boxScanned);
    }

    /**
     * The pallet whose label QR was read: the first of the two reads.
     */
    public function palletOf(string $labelScanned): Pallet
    {
        $token = PalletQr::tokenFrom($labelScanned) ?? throw PalletRuleViolation::unknownPalletQr();

        return Pallet::query()->where('qr_token', $token)->first() ?? throw PalletRuleViolation::unknownPalletQr();
    }
}
