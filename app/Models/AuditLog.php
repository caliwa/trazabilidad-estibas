<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only record of administration changes (users and roles). Operational traceability lives in trace_events.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $action
 * @property string|null $auditable_type
 * @property int|null $auditable_id
 * @property string|null $description
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property string|null $ip_address
 * @property CarbonImmutable $created_at
 * @property-read User|null $user
 */
#[Fillable(['user_id', 'action', 'auditable_type', 'auditable_id', 'description', 'old_values', 'new_values', 'ip_address'])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTIONS = [
        'user.created' => 'Usuario creado',
        'user.updated' => 'Usuario actualizado',
        'user.role_changed' => 'Cambio de rol',
        'user.deactivated' => 'Usuario desactivado',
        'user.reactivated' => 'Usuario reactivado',
        'user.password_set' => 'Contraseña asignada por el administrador',
        'setting.updated' => 'Parámetro cambiado',
        'pallet_close_reason.created' => 'Motivo de cierre creado',
        'pallet_close_reason.updated' => 'Motivo de cierre actualizado',
        'pallet_close_reason.deactivated' => 'Motivo de cierre desactivado',
        'pallet_close_reason.activated' => 'Motivo de cierre reactivado',
        'box_void_reason.created' => 'Motivo de anulación creado',
        'box_void_reason.updated' => 'Motivo de anulación actualizado',
        'box_void_reason.deactivated' => 'Motivo de anulación desactivado',
        'box_void_reason.activated' => 'Motivo de anulación reactivado',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
