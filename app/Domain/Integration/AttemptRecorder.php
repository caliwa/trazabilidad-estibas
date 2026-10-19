<?php

namespace App\Domain\Integration;

use App\Domain\Integration\Enums\AttemptOutcome;
use App\Integrations\Sap\Dto\SapWriteResult;
use App\Integrations\Sap\Dto\WriteOutcome;
use App\Models\IntegrationAttempt;
use App\Support\Logging\Redactor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Append-only record of every call made to SAP for a document, with its
 * latency and a normalized, sanitized result.
 */
class AttemptRecorder
{
    /**
     * @param  Model&object{id: int, request_uuid: string, payload_hash: string}  $document
     */
    public function write(Model $document, CarbonImmutable $startedAt, SapWriteResult $result): IntegrationAttempt
    {
        $outcome = match ($result->outcome) {
            WriteOutcome::Created => AttemptOutcome::Success,
            WriteOutcome::Rejected => AttemptOutcome::BusinessError,
            WriteOutcome::Uncertain => AttemptOutcome::Uncertain,
        };

        return $this->store($document, 'CREAR', $startedAt, $outcome, $result->httpStatus, $result->latencyMs, $result->errorCode, $result->errorMessage, [
            'doc_entry' => $result->docEntry,
            'doc_num' => $result->docNum,
        ]);
    }

    /**
     * @param  Model&object{id: int, request_uuid: string, payload_hash: string}  $document
     * @param  array<string, mixed>  $summary
     */
    public function lookup(Model $document, CarbonImmutable $startedAt, bool $failed, array $summary, ?string $error = null): IntegrationAttempt
    {
        return $this->store($document, 'CONSULTAR', $startedAt, $failed ? AttemptOutcome::QueryFailed : AttemptOutcome::Success, null, (int) $startedAt->diffInMilliseconds(now()), $failed ? 'CONSULTA_FALLIDA' : null, $error, $summary);
    }

    /**
     * @param  Model&object{id: int, request_uuid: string, payload_hash: string}  $document
     * @param  array<string, mixed>  $summary
     */
    private function store(Model $document, string $operation, CarbonImmutable $startedAt, AttemptOutcome $outcome, ?int $httpStatus, int $latencyMs, ?string $errorCode, ?string $errorMessage, array $summary): IntegrationAttempt
    {
        $number = (int) IntegrationAttempt::query()
            ->where('document_type', $document->getMorphClass())
            ->where('document_id', $document->id)
            ->max('attempt_number') + 1;

        $attempt = new IntegrationAttempt;
        $attempt->forceFill([
            'document_type' => $document->getMorphClass(),
            'document_id' => $document->id,
            'request_uuid' => $document->request_uuid,
            'attempt_number' => $number,
            'operation' => $operation,
            'started_at' => $startedAt,
            'finished_at' => now(),
            'outcome' => $outcome,
            'http_status' => $httpStatus,
            'latency_ms' => max(0, $latencyMs),
            'error_code' => $errorCode === null ? null : Str::limit($errorCode, 60, ''),
            'error_message' => $errorMessage === null ? null : Str::limit($errorMessage, 500, ''),
            'request_hash' => $operation === 'CREAR' ? $document->payload_hash : null,
            'response_summary' => Redactor::redact($summary),
        ])->save();

        Log::info('sap.'.strtolower($operation), Redactor::redact([
            'document' => $document->getMorphClass().':'.$document->id,
            'request_uuid' => $document->request_uuid,
            'outcome' => $outcome->value,
            'latency_ms' => $latencyMs,
            'error_code' => $errorCode,
        ]));

        return $attempt;
    }
}
