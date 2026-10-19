<?php

namespace App\Http\Requests;

use App\Domain\Pallets\Data\ScanEventData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape of a scan event. Content rules (order, item, lot, standard) are
 * checked by ProcessScanEvent, which stores rejected events; a request
 * that fails here has no trustworthy event id and is not stored.
 */
class StoreScanEventRequest extends FormRequest
{
    /**
     * Allowed clock difference with a device that reports a future time.
     */
    public const MAX_CLOCK_SKEW_MINUTES = 5;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'event_id' => ['required', 'string', 'min:1', 'max:100', 'regex:/^[\x21-\x7E]+$/'],
            'production_order' => ['required', 'string', 'max:50'],
            'item_code' => ['required', 'string', 'max:50'],
            'lot' => ['required', 'string', 'max:50'],
            'occurred_at' => ['required', 'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:s.uP,Y-m-d\TH:i:s\Z,Y-m-d\TH:i:s.u\Z', 'before_or_equal:'.now()->addMinutes(self::MAX_CLOCK_SKEW_MINUTES)->toIso8601String()],
        ];
    }

    public function toData(): ScanEventData
    {
        return new ScanEventData(
            externalEventId: $this->string('event_id')->toString(),
            productionOrderRef: trim($this->string('production_order')->toString()),
            itemCode: trim($this->string('item_code')->toString()),
            lotCode: trim($this->string('lot')->toString()),
            occurredAt: CarbonImmutable::parse($this->string('occurred_at')->toString())->setTimezone(config()->string('app.timezone')),
        );
    }
}
