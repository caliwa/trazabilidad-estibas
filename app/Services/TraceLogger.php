<?php

namespace App\Services;

use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Domain\Shared\TraceResult;
use App\Models\TraceEvent;
use App\Support\Logging\Redactor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Writes trace_events. Call it inside the use case's transaction: if the
 * operation rolls back, its trace rolls back with it, and a committed
 * operation always has its trace.
 */
class TraceLogger
{
    /**
     * @param  array<string, mixed>|null  $from
     * @param  array<string, mixed>|null  $to
     * @param  array<string, mixed>|null  $sapRefs
     * @param  array<string, mixed>|null  $context
     */
    public function record(
        TraceContext $ctx,
        TraceEventType $event,
        ?Model $subject = null,
        ?array $from = null,
        ?array $to = null,
        TraceResult $result = TraceResult::Ok,
        ?string $reason = null,
        ?array $sapRefs = null,
        ?array $context = null,
    ): TraceEvent {
        $trace = new TraceEvent;
        $trace->forceFill([
            'occurred_at' => now(),
            'event' => $event->value,
            'actor_type' => $ctx->actorType,
            'user_id' => $ctx->userId,
            'device_id' => $ctx->deviceId,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'from_state' => $from,
            'to_state' => $to,
            'reason' => $reason,
            'origin' => $ctx->origin,
            'correlation_id' => $ctx->correlationId,
            'result' => $result,
            'sap_refs' => $sapRefs,
            'context' => $context === null ? null : Redactor::redact($context),
            'ip_address' => $ctx->ipAddress,
        ])->save();

        Log::info('trace.'.$event->value, Redactor::redact([
            'correlation_id' => $ctx->correlationId,
            'actor' => $ctx->actorType->value,
            'user_id' => $ctx->userId,
            'device_id' => $ctx->deviceId,
            'subject' => $subject === null ? null : $subject->getMorphClass().':'.$subject->getKey(),
            'result' => $result->value,
            'sap_refs' => $sapRefs,
        ]));

        return $trace;
    }
}
