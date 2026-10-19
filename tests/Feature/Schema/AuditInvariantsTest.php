<?php

namespace Tests\Feature\Schema;

use App\Domain\Audits\Enums\CountStatus;
use App\Domain\Audits\Enums\DiscrepancyStatus;
use App\Models\DiscrepancyCase;
use App\Models\PalletAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsDatabaseRejects;
use Tests\TestCase;

class AuditInvariantsTest extends TestCase
{
    use AssertsDatabaseRejects, RefreshDatabase;

    public function test_one_count_in_progress_per_pallet(): void
    {
        $audit = PalletAudit::factory()->create();

        $this->assertDatabaseRejects(
            fn () => PalletAudit::factory()->create(['pallet_id' => $audit->pallet_id, 'attempt' => 2]),
            'pallet_audits_one_open',
        );
    }

    public function test_the_result_must_match_the_numbers(): void
    {
        $audit = PalletAudit::factory()->create();
        $finish = fn (CountStatus $status, int $counted, int $expected) => $audit->fresh()?->forceFill(['status' => $status, 'counted_boxes' => $counted, 'expected_boxes' => $expected, 'completed_at' => now()])->save();

        $this->assertDatabaseRejects(fn () => $finish(CountStatus::Approved, 39, 40), 'pallet_audits_approved_matches_check');
        $this->assertDatabaseRejects(fn () => $finish(CountStatus::Discrepancy, 40, 40), 'pallet_audits_discrepancy_differs_check');
        $this->assertDatabaseRejects(fn () => $finish(CountStatus::Approved, -1, -1), 'pallet_audits_counts_not_negative_check');
    }

    public function test_a_finished_count_is_evidence_and_never_changes(): void
    {
        $audit = PalletAudit::factory()->create();
        $audit->forceFill(['status' => CountStatus::Discrepancy, 'counted_boxes' => 38, 'expected_boxes' => 40, 'completed_at' => now()])->save();

        $this->assertDatabaseRejects(fn () => $audit->forceFill(['counted_boxes' => 40, 'status' => CountStatus::Approved])->save(), 'ya terminó');
        $this->assertDatabaseRejects(fn () => $audit->delete(), 'no_delete');
    }

    public function test_expected_boxes_is_never_serialized(): void
    {
        $audit = PalletAudit::factory()->create();
        $audit->forceFill(['status' => CountStatus::Discrepancy, 'counted_boxes' => 38, 'expected_boxes' => 40, 'completed_at' => now()])->save();

        $this->assertArrayNotHasKey('expected_boxes', $audit->refresh()->toArray());
        $this->assertStringNotContainsString('"expected_boxes"', $audit->toJson());
    }

    public function test_a_resolved_discrepancy_records_who_what_and_why_and_is_then_closed(): void
    {
        $audit = PalletAudit::factory()->create();
        $audit->forceFill(['status' => CountStatus::Discrepancy, 'counted_boxes' => 38, 'expected_boxes' => 40, 'completed_at' => now()])->save();

        $case = new DiscrepancyCase;
        $case->forceFill(['pallet_id' => $audit->pallet_id, 'pallet_audit_id' => $audit->id, 'status' => DiscrepancyStatus::Open, 'opened_at' => now()])->save();

        $this->assertDatabaseRejects(fn () => $case->forceFill(['status' => DiscrepancyStatus::Resolved, 'resolved_at' => now()])->save(), 'discrepancy_cases_resolution_check');

        $case->forceFill([
            'status' => DiscrepancyStatus::Resolved,
            'resolved_at' => now(),
            'resolved_by' => User::factory()->create()->id,
            'resolution_action' => 'REAUDITORIA',
            'resolution_notes' => 'Segundo conteo por otra persona.',
        ])->save();

        $this->assertDatabaseRejects(fn () => $case->forceFill(['resolution_notes' => 'Cambiado'])->save(), 'ya está resuelta');
    }
}
