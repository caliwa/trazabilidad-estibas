<?php

namespace App\Domain\Settings;

use App\Enums\Permission;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Reads and changes the parameters stored in app_settings. A parameter
 * nobody changed yet has its default. Every change is audited.
 */
class AppSettings
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function int(Setting $setting): int
    {
        $stored = DB::table('app_settings')->where('key', $setting->value)->value('value');

        return $stored === null ? $setting->default() : (int) json_decode((string) $stored, true);
    }

    public function set(User $actor, Setting $setting, int $value): void
    {
        Gate::forUser($actor)->authorize(Permission::SettingsManage->value);
        Validator::make(['value' => $value], ['value' => $setting->rules()], attributes: ['value' => mb_strtolower($setting->label())])->validate();

        DB::transaction(function () use ($actor, $setting, $value): void {
            $old = $this->int($setting);

            if ($old === $value) {
                return;
            }

            DB::table('app_settings')->upsert(
                [['key' => $setting->value, 'value' => json_encode($value), 'updated_by' => $actor->id, 'updated_at' => now()]],
                ['key'],
                ['value', 'updated_by', 'updated_at'],
            );

            $this->audit->record('setting.updated', null, $setting->label(), [$setting->value => $old], [$setting->value => $value], $actor);
        });
    }
}
