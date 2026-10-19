<?php

namespace Tests\Feature\Schema;

use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Domain\Shared\TraceResult;
use App\Models\Device;
use App\Models\Pallet;
use App\Models\TraceEvent;
use App\Models\User;
use App\Services\TraceLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Concerns\AssertsDatabaseRejects;
use Tests\TestCase;

/**
 * RN-13 and RN-16: every critical operation leaves an immutable trace with
 * actor, origin and correlation, and no secrets.
 */
class TraceEventsTest extends TestCase
{
    use AssertsDatabaseRejects, RefreshDatabase;

    public function test_the_logger_records_actor_subject_states_and_correlation(): void
    {
        $user = User::factory()->create();
        $pallet = Pallet::factory()->create();
        $correlation = (string) Str::uuid();

        app(TraceLogger::class)->record(
            TraceContext::forUser($user, Origin::Web, $correlation, '10.0.0.5'),
            TraceEventType::PalletClosedManually,
            $pallet,
            from: ['physical_status' => 'EN_ESTIBADO'],
            to: ['physical_status' => 'ESTIBADO_FINALIZADO'],
            reason: 'Fin de turno',
        );

        $trace = TraceEvent::sole();
        $this->assertSame('pallet.closed_manual', $trace->event);
        $this->assertSame($user->id, $trace->user_id);
        $this->assertSame('pallet', $trace->subject_type);
        $this->assertSame($pallet->id, $trace->subject_id);
        $this->assertSame(['physical_status' => 'ESTIBADO_FINALIZADO'], $trace->to_state);
        $this->assertSame($correlation, $trace->correlation_id);
        $this->assertSame(TraceResult::Ok, $trace->result);
        $this->assertSame('10.0.0.5', $trace->ip_address);
    }

    public function test_context_and_log_lines_never_carry_secrets(): void
    {
        Log::spy();
        $device = Device::factory()->create();

        app(TraceLogger::class)->record(
            TraceContext::forDevice($device, (string) Str::uuid()),
            TraceEventType::ScanRejected,
            result: TraceResult::Rejected,
            sapRefs: ['B1SESSION' => 'cookie-value'],
            context: ['token' => 'abc', 'nested' => ['Password' => 'x', 'lot' => 'L1']],
        );

        // jsonb does not keep key order, so compare as maps.
        $this->assertEquals(['token' => '[redactado]', 'nested' => ['Password' => '[redactado]', 'lot' => 'L1']], TraceEvent::sole()->context);
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $data) => ! str_contains((string) json_encode($data), 'cookie-value'));
    }

    public function test_traces_cannot_be_updated_deleted_or_truncated(): void
    {
        app(TraceLogger::class)->record(TraceContext::system(Origin::Job, (string) Str::uuid()), TraceEventType::MastersSynced);
        $trace = TraceEvent::sole();

        $this->assertDatabaseRejects(fn () => DB::table('trace_events')->update(['reason' => 'x']), 'append_only');
        $this->assertDatabaseRejects(fn () => DB::table('trace_events')->delete(), 'append_only');
        $this->assertDatabaseRejects(fn () => DB::statement('TRUNCATE trace_events'), 'append_only');
        $this->assertSame($trace->id, TraceEvent::sole()->id);
    }

    public function test_the_actor_must_be_identified(): void
    {
        $this->assertDatabaseRejects(fn () => DB::table('trace_events')->insert([
            'event' => 'pallet.created', 'actor_type' => 'USUARIO', 'user_id' => null,
            'origin' => 'WEB', 'correlation_id' => (string) Str::uuid(), 'result' => 'OK',
        ]), 'trace_events_actor_check');
    }
}
