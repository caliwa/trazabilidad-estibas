<?php

namespace App\Domain\Pallets\Actions;

use App\Domain\Pallets\Exceptions\PalletRuleViolation;
use App\Domain\Pallets\PalletQr;
use App\Domain\Shared\TraceContext;
use App\Models\Pallet;
use App\Models\User;

/**
 * Confirms the label applied by reading the QR already stuck on the pallet,
 * without picking the pallet first: the QR says which one it is. The rules
 * are ConfirmLabeled's.
 */
class ConfirmLabelByScan
{
    public function __construct(private readonly ConfirmLabeled $confirm) {}

    public function handle(User $user, string $scanned, TraceContext $ctx): Pallet
    {
        $token = PalletQr::tokenFrom($scanned) ?? throw PalletRuleViolation::unknownPalletQr();
        $pallet = Pallet::query()->where('qr_token', $token)->first() ?? throw PalletRuleViolation::unknownPalletQr();

        return $this->confirm->handle($user, $pallet->id, $scanned, $ctx);
    }
}
