<?php

namespace App\Domain\Audits\Actions;

use App\Domain\Audits\Data\BlindCountView;
use App\Domain\Audits\Enums\AuditStatus;
use App\Domain\Audits\Enums\CountStatus;
use App\Domain\Audits\Enums\DiscrepancyStatus;
use App\Domain\Audits\Exceptions\AuditRuleViolation;
use App\Domain\Pallets\PalletQr;
use App\Domain\Receipts\Enums\ReceiptStatus;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Domain\Transfers\Enums\TransferStatus;
use App\Enums\Permission;
use App\Models\DiscrepancyCase;
use App\Models\Pallet;
use App\Models\PalletAudit;
use App\Models\User;
use App\Services\TraceLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Scanned QR → a blind count in progress. The server checks everything
 * (receipt confirmed in SAP, not transferred, not approved, nobody else
 * counting, no discrepancy waiting) and returns identity only.
 *
 * A pallet with a discrepancy can be counted again only after a
 * supervisor enabled a reaudit (case EN_REVISION). Anyone who counts can
 * do it, the operator who counted before included.
 */
class StartBlindCount
{
    public function __construct(private readonly TraceLogger $trace) {}

    public function handle(User $user, string $scannedQr, TraceContext $ctx): BlindCountView
    {
        Gate::forUser($user)->authorize(Permission::AuditsCount->value);

        $token = PalletQr::tokenFrom($scannedQr) ?? throw AuditRuleViolation::unreadableQr();

        return DB::transaction(function () use ($user, $token, $ctx): BlindCountView {
            $pallet = Pallet::query()->with('item:id,description')->where('qr_token', $token)->lockForUpdate()->first()
                ?? throw AuditRuleViolation::unknownPallet();

            if ($pallet->transfer_status === TransferStatus::Confirmed) {
                throw AuditRuleViolation::alreadyTransferred($pallet->pallet_code);
            }

            if ($pallet->receipt_status !== ReceiptStatus::Confirmed) {
                throw AuditRuleViolation::notReceived($pallet->pallet_code);
            }

            $open = PalletAudit::query()->where('pallet_id', $pallet->id)->where('status', CountStatus::Counting)->first();

            if ($open !== null) {
                // The same person scanning again resumes their own count.
                return $open->operator_id === $user->id ? $this->view($pallet, $open) : throw AuditRuleViolation::countedByOther($pallet->pallet_code);
            }

            $reauditOf = null;

            match ($pallet->audit_status) {
                AuditStatus::Approved => throw AuditRuleViolation::alreadyApproved($pallet->pallet_code),
                AuditStatus::Counting => throw AuditRuleViolation::countedByOther($pallet->pallet_code),
                AuditStatus::Discrepancy => $reauditOf = $this->authorizedReaudit($pallet),
                AuditStatus::Pending => null,
            };

            $audit = new PalletAudit;
            $audit->forceFill([
                'pallet_id' => $pallet->id,
                'attempt' => (int) PalletAudit::query()->where('pallet_id', $pallet->id)->max('attempt') + 1,
                'operator_id' => $user->id,
                'status' => CountStatus::Counting,
                'started_at' => now(),
                'reaudit_of_id' => $reauditOf?->id,
            ])->save();

            $from = $pallet->audit_status;
            $pallet->forceFill(['audit_status' => AuditStatus::Counting])->save();

            $this->trace->record($ctx, TraceEventType::AuditStarted, $pallet,
                from: ['audit_status' => $from->value], to: ['audit_status' => AuditStatus::Counting->value],
                context: ['audit_id' => $audit->id, 'attempt' => $audit->attempt, 'reaudit_of' => $reauditOf?->id]);

            return $this->view($pallet, $audit);
        });
    }

    private function authorizedReaudit(Pallet $pallet): PalletAudit
    {
        $case = DiscrepancyCase::query()->where('pallet_id', $pallet->id)->where('status', '<>', DiscrepancyStatus::Resolved)->latest('id')->first();

        if ($case === null || $case->status !== DiscrepancyStatus::InReview) {
            throw AuditRuleViolation::discrepancyOpen($pallet->pallet_code);
        }

        return PalletAudit::query()->findOrFail($case->pallet_audit_id);
    }

    private function view(Pallet $pallet, PalletAudit $audit): BlindCountView
    {
        return new BlindCountView($audit->id, $pallet->pallet_code, $pallet->production_order_ref, $pallet->item_code, $pallet->item->description, $pallet->lot_code, $audit->reaudit_of_id !== null);
    }
}
