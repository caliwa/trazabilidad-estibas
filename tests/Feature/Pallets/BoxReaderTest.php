<?php

namespace Tests\Feature\Pallets;

use App\Domain\BoxLabels\Actions\ConsultProductionOrder;
use App\Domain\BoxLabels\Actions\GenerateBoxLabels;
use App\Domain\BoxLabels\BoxQr;
use App\Domain\Devices\ManageDevices;
use App\Domain\Operations\Heartbeat;
use App\Domain\Operations\OperationalChecks;
use App\Domain\Orders\Actions\SyncReleasedOrders;
use App\Domain\Pallets\Actions\RegisterManualBoxScan;
use App\Domain\Pallets\Data\ManualBoxScan;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Pallets\Exceptions\PalletRuleViolation;
use App\Domain\Pallets\Readers\BoxReaderSession;
use App\Domain\Pallets\Readers\BoxReadOutcome;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Enums\Role;
use App\Models\BoxConversionFactor;
use App\Models\BoxLabel;
use App\Models\BoxLabelBatch;
use App\Models\Device;
use App\Models\Pallet;
use App\Models\PalletScanEvent;
use App\Models\TraceEvent;
use App\Models\User;
use App\Services\TraceLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * A fixed reader on the line reads the box QR and sends it as text over
 * TCP or serial; boxes:reader keeps it connected and counts each box with
 * the same rules as a read by hand.
 */
class BoxReaderTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $operator;

    private BoxLabelBatch $batch;

    private Device $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->operator = $this->userWithRole(Role::ProductionOperator);
        config(['traceability.test_pallet_boxes' => 3]);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00'));

        $ctx = TraceContext::forUser($this->operator, Origin::Web, (string) Str::uuid());
        app(SyncReleasedOrders::class)->handle(null, $ctx);
        $order = app(ConsultProductionOrder::class)->handle($this->operator, '33128');
        BoxConversionFactor::factory()->create(['item_id' => $order->item_id, 'units_per_box' => '24']);
        $this->batch = app(GenerateBoxLabels::class)->handle($this->operator, $order->id, 10, CarbonImmutable::parse('2026-10-20'), CarbonImmutable::parse('2028-10-20'), $ctx);
        [$this->reader] = app(ManageDevices::class)->register('LEC-L1', 'Lector fijo línea 1', 'LECTOR', TraceContext::system(Origin::Console, (string) Str::uuid()));
    }

    private function box(int $n): string
    {
        return BoxQr::payload((string) BoxLabel::query()->where('box_label_batch_id', $this->batch->id)->where('sequence', $n)->value('qr_token'));
    }

    private function readerSession(): BoxReaderSession
    {
        return new BoxReaderSession($this->reader, app(RegisterManualBoxScan::class), app(TraceLogger::class));
    }

    private function counted(): int
    {
        return PalletScanEvent::query()->whereNotNull('pallet_id')->count();
    }

    public function test_each_line_the_reader_sends_counts_its_box_even_when_it_comes_in_pieces(): void
    {
        $session = $this->readerSession();
        $stream = "\x02".$this->box(1)."\x03\r\n".$this->box(2)."\r".$this->box(3)."\n";
        $reads = [];
        foreach (str_split($stream, 5) as $piece) {
            array_push($reads, ...$session->feed($piece));
        }

        $this->assertSame([BoxReadOutcome::Counted, BoxReadOutcome::Counted, BoxReadOutcome::Counted], array_map(fn ($r) => $r->outcome, $reads));
        // Three boxes with a standard of 3: the pallet closed on its own.
        $pallet = Pallet::query()->sole();
        $this->assertSame(3, $pallet->boxes_count);
        $this->assertSame(PhysicalStatus::Finished, $pallet->physical_status);
        // Each read keeps which reader read it.
        $this->assertSame([$this->reader->id], PalletScanEvent::query()->distinct()->pluck('read_by_device_id')->all());
    }

    public function test_fast_reads_count_however_the_reader_separates_them(): void
    {
        $session = $this->readerSession();
        $reads = [];

        // STX/ETX framing without line ends, two reads glued together, a read split across chunks,
        // a failed read between them and a tab as separator.
        foreach (["\x02".$this->box(1)."\x03\x02".$this->box(2)."\x03", $this->box(3).$this->box(4), 'NoRead'."\t".substr($this->box(5), 0, 12), substr($this->box(5), 12)."\t".$this->box(6)] as $chunk) {
            array_push($reads, ...$session->feed($chunk));
        }

        $this->assertSame(
            [BoxReadOutcome::Counted, BoxReadOutcome::Counted, BoxReadOutcome::Counted, BoxReadOutcome::Counted, BoxReadOutcome::Rejected, BoxReadOutcome::Counted, BoxReadOutcome::Counted],
            array_map(fn ($r) => $r->outcome, $reads),
        );
        $this->assertSame(6, $this->counted());
    }

    public function test_a_passing_database_error_is_retried_and_any_other_does_not_stop_the_reader(): void
    {
        $session = new BoxReaderSession($this->reader, app(FlakyBoxScan::class), app(TraceLogger::class));
        // What PostgreSQL gives on a deadlock: SQLSTATE 40P01.
        $deadlock = new \PDOException('SQLSTATE[40P01]: deadlock detected');
        $deadlock->errorInfo = ['40P01', 7, 'deadlock detected'];
        FlakyBoxScan::$failures = [new QueryException('pgsql', 'insert', [], $deadlock)];
        // The deadlock is retried: the box counts.
        $this->assertSame(BoxReadOutcome::Counted, $session->feed($this->box(1)."\n")[0]->outcome);

        FlakyBoxScan::$failures = [new \RuntimeException('algo inesperado')];
        $reads = $session->feed($this->box(2)."\n".$this->box(3)."\n");
        // The unexpected error leaves that box not counted (and on record) but the next ones go on.
        $this->assertSame([BoxReadOutcome::Rejected, BoxReadOutcome::Counted], array_map(fn ($r) => $r->outcome, $reads));
        $this->assertStringContainsString('algo inesperado', $reads[0]->message);
        $this->assertSame(2, $this->counted());
    }

    public function test_a_burst_over_tcp_is_counted_box_by_box(): void
    {
        $burst = implode('', array_map(fn (int $n) => "\x02".$this->box($n)."\x03", range(1, 10)));
        $process = proc_open([PHP_BINARY, base_path('tests/Support/fake-box-reader.php'), 'server', $this->bytes($burst)], [1 => ['pipe', 'w']], $pipes);
        $port = trim((string) fgets($pipes[1]));

        $this->assertSame(0, Artisan::call('boxes:reader', ['device' => 'LEC-L1', '--connect' => "127.0.0.1:{$port}", '--once' => true]), Artisan::output());
        proc_close($process);

        $this->assertSame(10, $this->counted());
        // Standard of 3: three full pallets and one with the tenth box.
        $this->assertSame([3, 3, 3, 1], Pallet::query()->orderBy('id')->pluck('boxes_count')->map(fn ($n) => (int) $n)->all());
    }

    public function test_a_box_read_twice_by_the_line_or_again_by_hand_counts_once(): void
    {
        $session = $this->readerSession();

        $reads = $session->feed($this->box(1)."\n".$this->box(1)."\n");
        $this->assertSame([BoxReadOutcome::Counted, BoxReadOutcome::AlreadyRead], array_map(fn ($r) => $r->outcome, $reads));

        // Read by hand after the line: refused as already read.
        try {
            app(RegisterManualBoxScan::class)->handle($this->operator, $this->box(1), TraceContext::forUser($this->operator, Origin::Web, (string) Str::uuid()));
            $this->fail('La caja ya estaba contada.');
        } catch (PalletRuleViolation $e) {
            $this->assertStringContainsString('ya fue leída', $e->getMessage());
        }

        // Read by hand first, then by the line: the line skips it.
        app(RegisterManualBoxScan::class)->handle($this->operator, $this->box(2), TraceContext::forUser($this->operator, Origin::Web, (string) Str::uuid()));
        $this->assertSame(BoxReadOutcome::AlreadyRead, $session->feed($this->box(2)."\n")[0]->outcome);

        $this->assertSame(2, $this->counted());
        $this->assertSame(0, TraceEvent::query()->where('event', TraceEventType::ScanRejected->value)->count());
    }

    public function test_a_box_the_reader_could_not_count_is_traced_on_the_reader_and_raises_an_alert(): void
    {
        $reads = $this->readerSession()->feed("NoRead\nTRZB:".str_repeat('X', 32)."\n");

        $this->assertSame([BoxReadOutcome::Rejected, BoxReadOutcome::Rejected], array_map(fn ($r) => $r->outcome, $reads));
        $this->assertSame(0, $this->counted());
        $traces = TraceEvent::query()->where('event', TraceEventType::ScanRejected->value)->where('subject_type', 'device')->where('subject_id', $this->reader->id)->get();
        $this->assertCount(2, $traces);
        $this->assertSame('NoRead', $traces[0]->context['read'] ?? null);

        $alert = collect(app(OperationalChecks::class)->run())->firstWhere('key', 'reader_not_counted');
        $this->assertNotNull($alert);
        $this->assertSame(2, $alert->count);
    }

    public function test_only_an_active_fixed_reader_can_count(): void
    {
        // The shared device of the box reads is created by the first read by hand.
        app(RegisterManualBoxScan::class)->handle($this->operator, $this->box(5), TraceContext::forUser($this->operator, Origin::Web, (string) Str::uuid()));
        $manual = Device::query()->where('code', RegisterManualBoxScan::DEVICE_CODE)->sole();
        $sensor = app(ManageDevices::class)->register('SEN-L1', 'Sensor', 'SENSOR', TraceContext::system(Origin::Console, (string) Str::uuid()))[0];
        $this->reader->forceFill(['active' => false])->save();

        foreach ([$this->reader, $manual, $sensor] as $device) {
            try {
                app(RegisterManualBoxScan::class)->fromReader($device, $this->box(1), TraceContext::forDevice($device, (string) Str::uuid()));
                $this->fail("{$device->code} no debía contar.");
            } catch (\InvalidArgumentException) {
            }
            $this->assertSame(1, Artisan::call('boxes:reader', ['device' => $device->code, '--connect' => '127.0.0.1:1', '--once' => true]));
        }
        $this->assertSame(1, $this->counted());
    }

    public function test_the_command_connects_to_a_reader_that_is_the_tcp_server(): void
    {
        $file = $this->bytes($this->box(1)."\r\n".$this->box(2)."\r\n");
        $process = proc_open([PHP_BINARY, base_path('tests/Support/fake-box-reader.php'), 'server', $file], [1 => ['pipe', 'w']], $pipes);
        $port = trim((string) fgets($pipes[1]));

        $status = Artisan::call('boxes:reader', ['device' => 'lec-l1', '--connect' => "127.0.0.1:{$port}", '--once' => true]);
        proc_close($process);

        $this->assertSame(0, $status, Artisan::output());
        $this->assertSame(2, $this->counted());
        $this->assertTrue(Heartbeat::isFresh(Heartbeat::reader('LEC-L1')));
    }

    public function test_the_command_waits_for_a_reader_that_is_the_tcp_client(): void
    {
        $free = stream_socket_server('tcp://127.0.0.1:0');
        $port = substr((string) strrchr((string) stream_socket_get_name($free, false), ':'), 1);
        fclose($free);
        $file = $this->bytes($this->box(3)."\n");
        $process = proc_open([PHP_BINARY, base_path('tests/Support/fake-box-reader.php'), 'client', $port, $file], [], $pipes);

        $status = Artisan::call('boxes:reader', ['device' => 'LEC-L1', '--listen' => "127.0.0.1:{$port}", '--once' => true]);
        proc_close($process);

        $this->assertSame(0, $status, Artisan::output());
        $this->assertSame(1, $this->counted());
    }

    public function test_a_reader_that_was_connected_and_no_longer_is_raises_an_alert(): void
    {
        $alerts = fn () => collect(app(OperationalChecks::class)->run())->pluck('key')->all();

        // Never connected: maybe not installed yet, no alert.
        $this->assertNotContains('reader:LEC-L1', $alerts());

        Heartbeat::record(Heartbeat::reader('LEC-L1'));
        $this->assertNotContains('reader:LEC-L1', $alerts());

        $this->travel(config()->integer('operations.heartbeat_max_age_minutes') + 1)->minutes();
        $this->assertContains('reader:LEC-L1', $alerts());
    }

    public function test_the_station_shows_each_line_read_in_green_or_red(): void
    {
        $station = fn () => Livewire::actingAs($this->operator)->test('pages::production.station');

        $station()->assertSee('Lectura automática de la línea')->assertSee('LEC-L1: sin conectar aún')->assertSee('Todavía no hay lecturas');

        Heartbeat::record(Heartbeat::reader('LEC-L1'));
        $this->readerSession()->feed($this->box(1)."\n");
        $box = (string) BoxLabel::query()->where('box_label_batch_id', $this->batch->id)->where('sequence', 1)->value('code');
        $station()->assertSee('LEC-L1: conectado')
            ->assertSeeHtml('data-line-result="ok"')
            ->assertSee($box)
            ->assertSee('EST-');

        $this->travel(5)->seconds();
        $this->readerSession()->feed("NoRead\n");
        // NO_READ: the reader fired but read nothing, said as such.
        $station()->assertSeeHtml('data-line-result="error"')
            ->assertSee('El lector no logró leer')
            ->assertSeeHtml('data-line-no-read')
            ->assertSee('Lee esa caja a mano')
            ->assertSee('NoRead')
            ->assertSee('Últimas cajas no contadas por el lector')
            ->assertSeeHtml('data-line-recent')
            ->assertSee('Últimas lecturas del lector');

        $this->travel(5)->seconds();
        $this->readerSession()->feed($this->box(2)."\n");
        $station()->assertSeeHtml('data-line-result="ok"')->assertSee('Últimas cajas no contadas por el lector');
    }

    public function test_the_station_hides_the_line_panel_without_fixed_readers(): void
    {
        $this->reader->forceFill(['active' => false])->save();

        Livewire::actingAs($this->operator)->test('pages::production.station')->assertDontSee('Lectura automática de la línea');
    }

    private function bytes(string $content): string
    {
        $file = tempnam(sys_get_temp_dir(), 'reader');
        file_put_contents($file, $content);
        $this->beforeApplicationDestroyed(fn () => @unlink($file));

        return $file;
    }
}

/** A box scan that fails first with the errors queued in $failures. */
class FlakyBoxScan extends RegisterManualBoxScan
{
    /** @var list<\Throwable> */
    public static array $failures = [];

    public function fromReader(Device $reader, string $scanned, TraceContext $ctx): ManualBoxScan
    {
        if (self::$failures !== []) {
            throw array_shift(self::$failures);
        }

        return parent::fromReader($reader, $scanned, $ctx);
    }

    public function test_a_no_read_from_the_reader_or_by_hand_says_the_reader_fired_but_read_nothing(): void
    {
        foreach (['NO_READ', 'NoRead', 'NOREAD', 'no read'] as $text) {
            $this->travel(1)->seconds();
            $reads = $this->readerSession()->feed($text."\n");
            $this->assertSame(BoxReadOutcome::Rejected, $reads[0]->outcome);
            $this->assertStringContainsString('El lector se activó pero no logró leer la etiqueta', $reads[0]->message);
        }
        $this->assertTrue((bool) (TraceEvent::query()->where('event', TraceEventType::ScanRejected->value)->latest('id')->first()?->context['no_read'] ?? false));
        $this->assertSame(0, $this->counted());

        // The same in the manual field (a PDA that sends its no-read text).
        Livewire::actingAs($this->operator)->test('pages::production.station')
            ->call('scanBox', 'NO_READ', 'scan-1')
            ->assertSet('boxNoRead', true)
            ->assertSee('El lector no logró leer')
            ->assertSee('El lector se activó pero no logró leer la etiqueta (NO_READ)')
            ->call('scanBox', 'TRZB:'.str_repeat('0', 32), 'scan-2')
            ->assertSet('boxNoRead', false)
            ->assertSee('Caja no contada');
    }
}
