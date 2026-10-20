<?php

namespace App\Console\Commands;

use App\Domain\Audits\Actions\CancelBlindCount;
use App\Domain\Audits\Enums\CountStatus;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Models\PalletAudit;
use App\Support\Correlation\CorrelationId;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('audits:expire-counts')]
#[Description('Cancela los conteos ciegos abiertos hace más del tiempo permitido, para que la estiba se pueda volver a contar.')]
class ExpireOpenCounts extends Command
{
    public function handle(CancelBlindCount $cancel, CorrelationId $correlation): int
    {
        $minutes = config()->integer('traceability.count_timeout_minutes');

        $expired = PalletAudit::query()
            ->where('status', CountStatus::Counting)
            ->where('started_at', '<=', now()->subMinutes($minutes))
            ->pluck('id')
            ->each(fn (int $id) => $cancel->handle(null, $id, "Conteo abierto más de {$minutes} minutos.", TraceContext::system(Origin::Job, $correlation->get())))
            ->count();

        $this->info("Conteos vencidos: {$expired}");

        return self::SUCCESS;
    }
}
