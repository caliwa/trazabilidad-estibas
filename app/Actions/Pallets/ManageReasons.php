<?php

namespace App\Actions\Pallets;

use App\Domain\Pallets\Enums\ReasonList;
use App\Enums\Permission;
use App\Models\BoxVoidReason;
use App\Models\PalletCloseReason;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Maintains the configurable lists of reasons (closing an incomplete pallet,
 * voiding a box). Reasons are deactivated, never deleted: the records that
 * used them keep pointing to them.
 */
class ManageReasons
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, sort_order: int}  $data
     */
    public function create(User $actor, ReasonList $list, array $data): PalletCloseReason|BoxVoidReason
    {
        Gate::forUser($actor)->authorize(Permission::CloseReasonsManage->value);
        $name = $this->uniqueName($list, $data['name']);

        return DB::transaction(function () use ($actor, $list, $name, $data): PalletCloseReason|BoxVoidReason {
            $model = $list->model();
            $reason = $model::query()->create(['name' => $name, 'sort_order' => $data['sort_order'], 'active' => true]);
            $this->audit->record($list->auditPrefix().'.created', $reason, $reason->name, null, ['name' => $reason->name, 'sort_order' => $reason->sort_order], $actor);

            return $reason;
        });
    }

    /**
     * @param  array{name: string, sort_order: int}  $data
     */
    public function update(User $actor, ReasonList $list, PalletCloseReason|BoxVoidReason $reason, array $data): void
    {
        Gate::forUser($actor)->authorize(Permission::CloseReasonsManage->value);
        $reason->fill(['name' => $this->uniqueName($list, $data['name'], $reason->id), 'sort_order' => $data['sort_order']]);

        if (! $reason->isDirty()) {
            return;
        }

        DB::transaction(function () use ($actor, $list, $reason): void {
            $old = array_intersect_key($reason->getOriginal(), $reason->getDirty());
            $new = $reason->getDirty();
            $reason->save();
            $this->audit->record($list->auditPrefix().'.updated', $reason, $reason->name, $old, $new, $actor);
        });
    }

    public function setActive(User $actor, ReasonList $list, PalletCloseReason|BoxVoidReason $reason, bool $active): void
    {
        Gate::forUser($actor)->authorize(Permission::CloseReasonsManage->value);

        if ($reason->active === $active) {
            return;
        }

        DB::transaction(function () use ($actor, $list, $reason, $active): void {
            $reason->forceFill(['active' => $active])->save();
            $this->audit->record($list->auditPrefix().($active ? '.activated' : '.deactivated'), $reason, $reason->name,
                ['active' => ! $active], ['active' => $active], $actor,
            );
        });
    }

    private function uniqueName(ReasonList $list, string $name, ?int $ignoreId = null): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        $model = $list->model();

        $taken = $model::query()
            ->whereRaw('lower(name) = lower(?)', [$name])
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['form.name' => 'Ya existe un motivo con ese nombre.']);
        }

        return $name;
    }
}
