<?php

namespace Tests\Feature\Pallets;

use App\Domain\BoxLabels\Actions\ConsultProductionOrder;
use App\Domain\BoxLabels\Actions\GenerateBoxLabels;
use App\Domain\BoxLabels\BoxQr;
use App\Domain\Orders\Actions\SyncReleasedOrders;
use App\Domain\Pallets\Actions\ConfirmLabeled;
use App\Domain\Pallets\Actions\ConfirmPalletized;
use App\Domain\Pallets\Actions\PrintLabel;
use App\Domain\Pallets\Actions\RegisterManualBoxScan;
use App\Domain\Pallets\Data\ManualBoxScan;
use App\Domain\Pallets\Data\ScanOutcome;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Pallets\Exceptions\PalletRuleViolation;
use App\Domain\Pallets\PalletQr;
use App\Domain\Shared\ActorType;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Enums\Role;
use App\Models\BoxConversionFactor;
use App\Models\BoxLabel;
use App\Models\BoxLabelBatch;
use App\Models\Pallet;
use App\Models\PalletScanEvent;
use App\Models\PalletStandard;
use App\Models\ProductionOrder;
use App\Models\TraceEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Box labels read by hand at the production station: the OF on the label
 * is validated (against SAP if needed), the box fills the pallet of its
 * order and lot, a test pallet standard stands in while the real one is
 * loaded, and the label of the pallet is confirmed by reading it.
 */
class ManualBoxScanTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $operator;

    private ProductionOrder $order;

    private BoxLabelBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->operator = $this->userWithRole(Role::ProductionOperator);
        config(['traceability.test_pallet_boxes' => 3, 'traceability.decisions.scan_ingestion_enabled' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00'));

        // OF 33128 (released in the simulator) with 24 units per box and one batch of labels.
        app(SyncReleasedOrders::class)->handle(null, $this->ctx());
        $this->order = app(ConsultProductionOrder::class)->handle($this->operator, '33128');
        BoxConversionFactor::factory()->create(['item_id' => $this->order->item_id, 'units_per_box' => '24']);
        $this->batch = app(GenerateBoxLabels::class)->handle($this->operator, $this->order->id, 10, CarbonImmutable::parse('2026-10-20'), CarbonImmutable::parse('2028-10-20'), $this->ctx());
    }

    private function ctx(?User $user = null): TraceContext
    {
        return TraceContext::forUser($user ?? $this->operator, Origin::Web, (string) Str::uuid());
    }

    /** What the reader types for box number $n of the batch: only that box's token. */
    private function box(int $n): string
    {
        return BoxQr::payload((string) BoxLabel::query()->where('box_label_batch_id', $this->batch->id)->where('sequence', $n)->value('qr_token'));
    }

    private function read(string $scanned, ?User $user = null): ManualBoxScan
    {
        $this->travel(2)->seconds();

        return app(RegisterManualBoxScan::class)->handle($user ?? $this->operator, $scanned, $this->ctx($user));
    }

    private function refused(string $scanned, string $message): void
    {
        try {
            $this->read($scanned);
            $this->fail('Debía rechazarse: '.$message);
        } catch (PalletRuleViolation $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_each_box_read_fills_the_pallet_of_its_order_and_lot_with_the_test_standard(): void
    {
        $first = $this->read($this->box(1));
        $this->assertSame(['33128', 'PT1350023', '33128293', '33128293-0001', '20/10/2028'], [$first->orderRef, $first->itemCode, $first->lotCode, $first->boxCode, $first->expiration->format('d/m/Y')]);
        $this->assertSame([ScanOutcome::Accepted, 1, 3, false], [$first->result->outcome, $first->result->boxesCount, $first->result->standard, $first->result->palletClosed]);
        $this->assertTrue($first->testStandard);

        $this->read($this->box(2));
        $third = $this->read($this->box(3));
        $this->assertSame([3, true], [$third->result->boxesCount, $third->result->palletClosed]);

        $closed = Pallet::query()->where('pallet_code', $third->result->palletCode)->sole();
        $this->assertSame(PhysicalStatus::Finished, $closed->physical_status);

        // The next box opens a new pallet with the same test standard, created once.
        $fourth = $this->read($this->box(4));
        $this->assertNotSame($third->result->palletCode, $fourth->result->palletCode);
        $this->assertSame(1, PalletStandard::query()->where('item_id', $this->order->item_id)->count());
        $this->assertSame(PalletStandard::TEST_SOURCE, PalletStandard::query()->where('item_id', $this->order->item_id)->value('sap_source_key'));

        // Read by a person: every box is on record with the manual reader, and the pallet trace names the user.
        $this->assertSame(4, PalletScanEvent::query()->whereHas('device', fn ($q) => $q->where('code', RegisterManualBoxScan::DEVICE_CODE))->count());
        $created = TraceEvent::query()->where('event', TraceEventType::PalletCreated->value)->first();
        $this->assertSame([ActorType::User, $this->operator->id], [$created?->actor_type, $created?->user_id]);
    }

    public function test_a_real_pallet_standard_is_used_when_there_is_one(): void
    {
        PalletStandard::factory()->create(['item_id' => $this->order->item_id, 'boxes_per_pallet' => 10]);

        $scan = $this->read($this->box(1));

        $this->assertSame(10, $scan->result->standard);
        $this->assertFalse($scan->testStandard);
    }

    public function test_what_a_hand_reader_types_is_read_even_with_another_keyboard_layout(): void
    {
        $token = fn (int $n) => substr($this->box($n), strlen(BoxQr::PREFIX));
        $variants = [
            $this->box(1)."\r\n",
            'TRZBÑ'.$token(2),
            strtoupper($this->box(3)),
            ' TRZB;'.$token(4).' ',
        ];

        foreach ($variants as $i => $variant) {
            $this->assertSame('33128293-000'.($i + 1), $this->read($variant)->boxCode);
        }

        $this->assertSame(4, PalletScanEvent::query()->count());
    }

    public function test_a_box_is_counted_only_once_by_its_code(): void
    {
        $first = $this->read($this->box(1));

        // The same box again (a second read, a retried request, a reader firing twice): refused, says where it is.
        foreach ([$this->box(1), str_replace("\n", "\r\n", $this->box(1))] as $again) {
            try {
                $this->read($again);
                $this->fail('Una caja no se cuenta dos veces');
            } catch (PalletRuleViolation $e) {
                $this->assertSame("La caja 33128293-0001 ya fue leída y está en la estiba {$first->result->palletCode}. No se cuenta otra vez.", $e->getMessage());
            }
        }

        $this->read($this->box(2));
        $this->assertSame(2, PalletScanEvent::query()->count());
        $this->assertSame(2, Pallet::query()->sole()->boxes_count);
        $this->assertSame(0, TraceEvent::query()->where('event', TraceEventType::ScanDuplicateMismatch->value)->count());
    }

    public function test_only_a_box_qr_printed_here_for_a_released_order_is_counted(): void
    {
        $pallet = Pallet::factory()->palletized()->create();

        $this->refused(BoxQr::payload(str_repeat('a', 32)), 'Ese QR no corresponde a ninguna caja generada en la aplicación');
        $this->refused(PalletQr::payload($pallet), 'Leíste el QR de una estiba');
        // The old label with the text inside the QR is not read any more.
        $this->refused($this->batch->qr_payload."\nCaja: 33128293-0001", 'no es el QR de una caja');
        $this->refused('cualquier cosa', 'no es el QR de una caja');

        ProductionOrder::query()->whereKey($this->order->id)->update(['sap_status' => 'CERRADO', 'is_open' => false]);
        $this->refused($this->box(1), 'La OF 33128 está «Cerrado» en SAP');

        $this->assertSame(0, PalletScanEvent::query()->count());
    }

    public function test_only_people_who_operate_the_station_read_boxes(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->read($this->box(1), $this->userWithRole(Role::LogisticsOperator));
    }

    /** Reads boxes $from..$to (a full pallet with the test standard of 3), palletizes and prints it. */
    private function labeledPallet(int $from, int $to): Pallet
    {
        foreach (range($from, $to) as $n) {
            $this->read($this->box($n));
        }
        $pallet = Pallet::query()->latest('id')->firstOrFail();
        app(ConfirmPalletized::class)->handle($this->operator, $pallet->id, $this->ctx());
        app(PrintLabel::class)->handle($this->operator, $pallet->id, null, $this->ctx());

        return $pallet->refresh();
    }

    public function test_the_label_is_confirmed_with_a_box_of_the_pallet_whose_button_was_pressed(): void
    {
        config(['traceability.decisions.label_confirmation' => 'scan']);
        $first = $this->labeledPallet(1, 3);
        $second = $this->labeledPallet(4, 6);
        $confirm = fn (Pallet $pallet, ?string $box) => app(ConfirmLabeled::class)->handle($this->operator, $pallet->id, $box, $this->ctx());
        $refused = function (?string $box, string $message) use ($confirm, $first): void {
            try {
                $confirm($first, $box);
                $this->fail('Debía rechazarse: '.$message);
            } catch (PalletRuleViolation $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        };

        $refused(null, 'Lee el QR de una caja');
        $refused('', 'Lee el QR de una caja');
        // The pallet's own label is not a box: the box is what proves where the label went.
        $refused(PalletQr::payload($first), 'no es el QR de una caja');
        $refused('TRZB:'.str_repeat('0', 32), 'no es el QR de una caja');
        $refused($this->box(9), 'no se ha leído en ninguna estiba');

        // A box of another pallet: the label is not on the right one. Refused and on record.
        $refused($this->box(5), "La caja leída es de {$second->pallet_code}, no de {$first->pallet_code}");
        $mismatch = TraceEvent::query()->where('event', TraceEventType::PalletLabelMismatch->value)->sole();
        $this->assertSame([$first->id, '33128293-0005', $second->pallet_code], [$mismatch->subject_id, $mismatch->context['box_code'] ?? null, $mismatch->context['box_pallet'] ?? null]);
        $this->assertSame(PhysicalStatus::Palletized, $first->refresh()->physical_status);

        // Keyboard layout and caps lock on the reader; tries are not limited.
        $this->assertSame(PhysicalStatus::Labeled, $confirm($first, 'TRZBÑ'.strtoupper((string) BoxLabel::query()->where('code', '33128293-0002')->value('qr_token'))."\r\n")->physical_status);
        $this->assertSame('33128293-0002', TraceEvent::query()->where('event', TraceEventType::PalletLabeled->value)->sole()->context['box_code'] ?? null);
    }

    public function test_the_station_shows_each_read_and_why_a_box_was_not_counted(): void
    {
        Livewire::actingAs($this->operator)->test('pages::production.station')
            ->assertSee('Lectura manual de cajas')
            ->call('scanBox', $this->box(1), (string) Str::uuid())
            ->assertSee('Caja contada')
            ->assertSee('33128293-0001')
            ->assertSee('20/10/2028')
            ->assertSee('Estándar de prueba')
            ->call('scanBox', 'cualquier cosa', (string) Str::uuid())
            ->assertSee('Caja no contada')
            ->assertSee('no es el QR de una caja')
            ->call('scanBox', $this->box(1), (string) Str::uuid())
            ->assertSee('ya fue leída');

        config(['traceability.decisions.label_confirmation' => 'scan']);
        $this->read($this->box(2));
        $this->read($this->box(3));
        $pallet = Pallet::query()->latest('id')->firstOrFail();
        app(ConfirmPalletized::class)->handle($this->operator, $pallet->id, $this->ctx());
        app(PrintLabel::class)->handle($this->operator, $pallet->id, null, $this->ctx());
        $other = $this->labeledPallet(4, 6);

        // The box is read from the pallet's "Confirmar etiqueta aplicada" button; the old section is gone.
        Livewire::actingAs($this->operator)->test('pages::production.station')
            ->assertDontSee('Confirmar etiqueta pegada')
            ->call('askConfirmLabel', $pallet->id)
            ->assertSee('QR de una caja de la estiba')
            ->assertDontSee('QR de la etiqueta pegada')
            ->set('scannedBoxQr', $this->box(5))
            ->call('confirmLabel')
            ->assertSeeHtml('data-label-result="error"')
            ->assertSee("La caja leída es de {$other->pallet_code}, no de {$pallet->pallet_code}")
            // Another box, as many times as needed.
            ->assertSet('scannedBoxQr', '')
            ->set('scannedBoxQr', $this->box(2))
            ->call('confirmLabel')
            ->assertSet('confirmError', null)
            ->assertDontSeeHtml('data-label-result="error"');

        $this->assertSame(PhysicalStatus::Labeled, $pallet->refresh()->physical_status);
    }

    public function test_the_blind_count_finds_the_pallet_with_the_scanner(): void
    {
        $counter = $this->userWithRole(Role::LogisticsOperator);
        $pallet = Pallet::factory()->received()->create();

        Livewire::actingAs($counter)->test('pages::cedi.count')
            ->call('scanFrom', 'TRZ1;'.strtoupper($pallet->qr_token), (string) Str::uuid())
            ->assertSee($pallet->pallet_code)
            ->assertSee('Cajas contadas')
            ->assertDontSee((string) $pallet->boxes_count.' cajas');
    }

    public function test_the_box_qr_carries_only_the_token(): void
    {
        $token = BoxQr::newToken();
        $this->assertSame('TRZB:'.$token, BoxQr::payload($token));
        $this->assertSame($token, BoxQr::tokenFrom('trzb-'.strtoupper($token)."\n"));
        $this->assertNull(BoxQr::tokenFrom('TRZ1:'.$token));
        $this->assertNull(BoxQr::tokenFrom('TRZB:'.substr($token, 1)));
        $this->assertNull(BoxQr::tokenFrom("TRZB:{$token}' OR 1=1"));
        $this->assertSame(32, strlen((string) BoxLabel::query()->value('qr_token')));
        $this->assertStringContainsString('^FDMA,'.$this->box(1).'^FS', $this->batch->zpl);
    }
}
