<?php

/*
 * Child process for ConcurrencyTest. Boots the application against the
 * concurrency database, waits for the start signal so every worker hits
 * PostgreSQL at the same moment, then runs its batch and prints a JSON
 * summary.
 *
 * Usage: php tests/Concurrency/worker.php '<json job>'
 */

use App\Domain\Pallets\Actions\CloseManually;
use App\Domain\Pallets\Actions\ProcessScanEvent;
use App\Domain\Pallets\Data\ScanEventData;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Pallets\Exceptions\PalletRuleViolation;
use App\Domain\Receipts\Actions\RequestProductionReceipts;
use App\Domain\Receipts\Exceptions\ReceiptRuleViolation;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Domain\Transfers\Actions\RequestInventoryTransfers;
use App\Domain\Transfers\Enums\TransferMode;
use App\Domain\Transfers\Exceptions\TransferRuleViolation;
use App\Models\Device;
use App\Models\Pallet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** @var array{mode: string, go: string, device_id?: int, user_id?: int, order?: string, item?: string, lot?: string, events?: list<string>, rounds?: int, pallet_ids?: list<int>, transfer_mode?: string} $job */
$job = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);

config(['traceability.decisions.scan_ingestion_enabled' => true]);

$deadline = microtime(true) + 20;
while (! file_exists($job['go'])) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, "sin señal de inicio\n");
        exit(2);
    }
    usleep(1000);
}

$summary = ['accepted' => 0, 'duplicate' => 0, 'rejected' => 0, 'closed' => 0, 'close_refused' => 0, 'errors' => []];

if ($job['mode'] === 'scan') {
    $device = Device::query()->findOrFail($job['device_id']);
    $process = $app->make(ProcessScanEvent::class);

    foreach ($job['events'] ?? [] as $eventId) {
        try {
            $result = $process->handle($device, new ScanEventData($eventId, $job['order'], $job['item'], $job['lot'], CarbonImmutable::now()), TraceContext::forDevice($device, (string) Str::uuid()));
            $summary[$result->outcome->value]++;
        } catch (Throwable $e) {
            $summary['errors'][] = get_class($e).': '.Str::limit($e->getMessage(), 300);
        }
    }
} elseif ($job['mode'] === 'receipt') {
    $user = User::query()->findOrFail($job['user_id']);

    try {
        $app->make(RequestProductionReceipts::class)->handle($user, $job['pallet_ids'] ?? [], false, TraceContext::forUser($user, Origin::Job, (string) Str::uuid()));
        $summary['accepted']++;
    } catch (ReceiptRuleViolation) {
        $summary['rejected']++;
    } catch (Throwable $e) {
        $summary['errors'][] = get_class($e).': '.Str::limit($e->getMessage(), 300);
    }
} elseif ($job['mode'] === 'transfer') {
    $user = User::query()->findOrFail($job['user_id']);
    config(['traceability.decisions.grouped_transfers_enabled' => true]);

    try {
        $app->make(RequestInventoryTransfers::class)->handle($user, $job['pallet_ids'] ?? [], TransferMode::from($job['transfer_mode'] ?? TransferMode::Individual->value), true, TraceContext::forUser($user, Origin::Job, (string) Str::uuid()));
        $summary['accepted']++;
    } catch (TransferRuleViolation) {
        $summary['rejected']++;
    } catch (Throwable $e) {
        $summary['errors'][] = get_class($e).': '.Str::limit($e->getMessage(), 300);
    }
} else {
    $user = User::query()->findOrFail($job['user_id']);
    $close = $app->make(CloseManually::class);

    for ($i = 0; $i < ($job['rounds'] ?? 0); $i++) {
        $open = Pallet::query()->where('lot_code', $job['lot'])->where('physical_status', PhysicalStatus::Stacking)->where('boxes_count', '>', 0)->value('id');

        if ($open !== null) {
            try {
                $close->handle($user, $open, 'prueba de concurrencia', TraceContext::forUser($user, Origin::Job, (string) Str::uuid()));
                $summary['closed']++;
            } catch (PalletRuleViolation) {
                // A scan completed it, or another close won: expected under contention.
                $summary['close_refused']++;
            } catch (Throwable $e) {
                $summary['errors'][] = get_class($e).': '.Str::limit($e->getMessage(), 300);
            }
        }

        usleep(random_int(500, 3000));
    }
}

echo json_encode($summary, JSON_THROW_ON_ERROR);
