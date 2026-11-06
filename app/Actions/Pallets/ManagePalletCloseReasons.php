<?php

namespace App\Actions\Pallets;

use App\Enums\Permission;
use App\Models\PalletCloseReason;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Maintains the reasons offered when an incomplete pallet is closed by hand.
 * Reasons are deactivated, never deleted: closed pallets keep pointing to them.
 */
class ManagePalletCloseReasons
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, sort_order: int}  $data
     */
    public function create(User $actor, array $data): PalletCloseReason
    {
        Gate::forUser($actor)->authorize(Permission::CloseReasonsManage->value);
        $name = $this->uniqueName($data['name']);

        return DB::transaction(function () use ($actor, $name, $data): PalletCloseReason {
            $reason = PalletCloseReason::query()->create(['name' => $name, 'sort_order' => $data['sort_order'], 'active' => true]);
            $this->audit->record('pallet_close_reason.created', $reason, $reason->name, null, ['name' => $reason->name, 'sort_order' => $reason->sort_order], $actor);

            return $reason;
        });
    }

    /**
     * @param  array{name: string, sort_order: int}  $data
     */
    public function update(User $actor, PalletCloseReason $reason, array $data): void
    {
        Gate::forUser($actor)->authorize(Permission::CloseReasonsManage->value);
        $reason->fill(['name' => $this->uniqueName($data['name'], $reason->id), 'sort_order' => $data['sort_order']]);

        if (! $reason->isDirty()) {
            return;
        }

        DB::transaction(function () use ($actor, $reason): void {
            $old = array_intersect_key($reason->getOriginal(), $reason->getDirty());
            $new = $reason->getDirty();
            $reason->save();
            $this->audit->record('pallet_close_reason.updated', $reason, $reason->name, $old, $new, $actor);
        });
    }

    public function setActive(User $actor, PalletCloseReason $reason, bool $active): void
    {
        Gate::forUser($actor)->authorize(Permission::CloseReasonsManage->value);

        if ($reason->active === $active) {
            return;
        }

        DB::transaction(function () use ($actor, $reason, $active): void {
            $reason->forceFill(['active' => $active])->save();
            $this->audit->record($active ? 'pallet_close_reason.activated' : 'pallet_close_reason.deactivated', $reason, $reason->name,
                ['active' => ! $active], ['active' => $active], $actor,
            );
        });
    }

    private function uniqueName(string $name, ?int $ignoreId = null): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

        $taken = PalletCloseReason::query()
            ->whereRaw('lower(name) = lower(?)', [$name])
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['form.name' => 'Ya existe un motivo con ese nombre.']);
        }

        return $name;
    }
}
