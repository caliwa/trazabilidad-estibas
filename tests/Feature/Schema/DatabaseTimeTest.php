<?php

namespace Tests\Feature\Schema;

use App\Models\Pallet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Dates must keep their instant and their microseconds, whatever the time
 * zone of the value, of the app and of the database session.
 */
class DatabaseTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_eloquent_stores_the_instant_with_microseconds(): void
    {
        $pallet = Pallet::factory()->create(['started_at' => CarbonImmutable::parse('2026-10-19T15:00:00.123456Z')]);

        $utc = DB::table('pallets')->where('id', $pallet->id)->selectRaw("to_char(started_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') AS v")->value('v');

        $this->assertSame('2026-10-19 15:00:00.123456', $utc);
        $this->assertTrue($pallet->refresh()->started_at->equalTo(CarbonImmutable::parse('2026-10-19T10:00:00.123456-05:00')));
    }

    public function test_new_timestamptz_columns_have_microsecond_precision(): void
    {
        $precision = DB::scalar("SELECT datetime_precision FROM information_schema.columns WHERE table_name = 'pallet_scan_events' AND column_name = 'event_occurred_at'");

        $this->assertSame(6, (int) $precision);
    }

    public function test_the_corrective_migration_raises_old_whole_second_columns(): void
    {
        DB::statement('CREATE TABLE precision_probe (at timestamp(0) with time zone)');

        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_10_21_000001_use_microsecond_precision_for_timestamptz.php');
        $migration->up();

        $this->assertSame(6, (int) DB::scalar("SELECT datetime_precision FROM information_schema.columns WHERE table_name = 'precision_probe'"));
    }
}
