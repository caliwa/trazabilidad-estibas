<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Writes the administration audit trail.
 */
class AuditLogger
{
    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function record(string $action, ?Model $subject = null, ?string $description = null, ?array $old = null, ?array $new = null, ?User $actor = null): AuditLog
    {
        $log = new AuditLog([
            'user_id' => $actor->id ?? $this->request->user()?->id,
            'action' => $action,
            'description' => $description,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $this->request->ip(),
        ]);

        if ($subject !== null) {
            $log->auditable()->associate($subject);
        }

        $log->save();

        return $log;
    }
}
