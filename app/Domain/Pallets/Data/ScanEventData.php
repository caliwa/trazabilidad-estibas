<?php

namespace App\Domain\Pallets\Data;

use Carbon\CarbonImmutable;

/**
 * One box read, as received from a device. The payload hash covers the
 * business fields only, so a resend with the same content is recognized
 * and a reused id with different content is flagged.
 */
final readonly class ScanEventData
{
    public string $payloadHash;

    public function __construct(
        public string $externalEventId,
        public string $productionOrderRef,
        public string $itemCode,
        public string $lotCode,
        public CarbonImmutable $occurredAt,
    ) {
        $this->payloadHash = hash('sha256', (string) json_encode([
            $externalEventId, $productionOrderRef, $itemCode, $lotCode, $occurredAt->utc()->format('Y-m-d\TH:i:s.u\Z'),
        ]));
    }
}
