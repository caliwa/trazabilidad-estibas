<?php

namespace Tests\Feature\Schema;

use App\Domain\Audits\Enums\AuditStatus;
use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Quality\Enums\QualityStatus;
use App\Domain\Receipts\Enums\ReceiptStatus;
use App\Domain\Transfers\Enums\TransferStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The PHP enums and the database function state_transition_allowed() must
 * describe exactly the same state machines. Changing one without the other
 * fails here.
 */
class StateTransitionsMatchDatabaseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, class-string<\BackedEnum>}>
     */
    public static function machines(): array
    {
        return [
            'físico' => ['physical', PhysicalStatus::class],
            'recepción' => ['receipt', ReceiptStatus::class],
            'auditoría' => ['audit', AuditStatus::class],
            'traslado' => ['transfer', TransferStatus::class],
            'documento SAP' => ['sap_document', SapDocumentStatus::class],
            'calidad' => ['quality', QualityStatus::class],
        ];
    }

    /**
     * @param  class-string<PhysicalStatus|ReceiptStatus|AuditStatus|TransferStatus|SapDocumentStatus|QualityStatus>  $enum
     */
    #[DataProvider('machines')]
    public function test_enum_and_database_agree_on_every_pair(string $dimension, string $enum): void
    {
        foreach ($enum::cases() as $from) {
            foreach ($enum::cases() as $to) {
                $database = (bool) DB::scalar('SELECT state_transition_allowed(?, ?, ?)', [$dimension, $from->value, $to->value]);
                $php = $from === $to || $from->canTransitionTo($to);

                $this->assertSame($php, $database, "{$dimension}: {$from->value} → {$to->value}");
            }
        }
    }
}
