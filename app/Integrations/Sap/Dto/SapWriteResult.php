<?php

namespace App\Integrations\Sap\Dto;

final readonly class SapWriteResult
{
    private function __construct(
        public WriteOutcome $outcome,
        public ?int $docEntry,
        public ?int $docNum,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?int $httpStatus,
        public int $latencyMs,
        // The document as SAP answered it on creation, when the answer could be read.
        public ?SapDocument $document = null,
    ) {}

    public static function created(int $docEntry, int $docNum, int $latencyMs, ?int $httpStatus = null, ?SapDocument $document = null): self
    {
        return new self(WriteOutcome::Created, $docEntry, $docNum, null, null, $httpStatus, $latencyMs, $document);
    }

    public static function rejected(string $errorCode, string $errorMessage, int $latencyMs, ?int $httpStatus = null): self
    {
        return new self(WriteOutcome::Rejected, null, null, $errorCode, $errorMessage, $httpStatus, $latencyMs);
    }

    public static function uncertain(string $errorCode, string $errorMessage, int $latencyMs, ?int $httpStatus = null): self
    {
        return new self(WriteOutcome::Uncertain, null, null, $errorCode, $errorMessage, $httpStatus, $latencyMs);
    }
}
