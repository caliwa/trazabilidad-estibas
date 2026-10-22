<?php

namespace Tests\Feature\Pallets;

use App\Domain\BoxLabels\Actions\ConsultProductionOrder;
use App\Domain\BoxLabels\Actions\GenerateBoxLabels;
use App\Domain\BoxLabels\BoxLabelPayload;
use App\Domain\Pallets\Actions\ConfirmLabelByScan;
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
use App\Integrations\Sap\Contracts\SapGateway;
use App\Integrations\Sap\Fake\FakeSapGateway;
use App\Models\BoxConversionFactor;
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
        $this->order = app(ConsultProductionOrder::class)->handle($this->operator, '33128', $this->ctx());
        BoxConversionFactor::factory()->create(['item_id' => $this->order->item_id, 'units_per_box' => '24']);
        $this->batch = app(GenerateBoxLabels::class)->handle($this->operator, $this->order->id, 10, CarbonImmutable::parse('2026-10-20'), CarbonImmutable::parse('2028-10-20'), $this->ctx());
    }

    private function ctx(?User $user = null): TraceContext
    {
        return TraceContext::forUser($user ?? $this->operator, Origin::Web, (string) Str::uuid());
    }

    private function read(?string $scanned = null, ?string $id = null, ?User $user = null): ManualBoxScan
    {
        // A second apart, like boxes passing one by one.
        $this->travel(2)->seconds();

        return app(RegisterManualBoxScan::class)->handle($user ?? $this->operator, $scanned ?? $this->batch->qr_payload, $id ?? (string) Str::uuid(), $this->ctx($user));
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
        $first = $this->read();
        $this->assertSame(['33128', 'PT1350023', '33128293', '20/10/2028'], [$first->orderRef, $first->itemCode, $first->lotCode, $first->expiration->format('d/m/Y')]);
        $this->assertSame([ScanOutcome::Accepted, 1, 3, false], [$first->result->outcome, $first->result->boxesCount, $first->result->standard, $first->result->palletClosed]);
        $this->assertTrue($first->testStandard);

        $this->read();
        $third = $this->read();
        $this->assertSame([3, true], [$third->result->boxesCount, $third->result->palletClosed]);

        $closed = Pallet::query()->where('pallet_code', $third->result->palletCode)->sole();
        $this->assertSame(PhysicalStatus::Finished, $closed->physical_status);

        // The next box opens a new pallet with the same test standard, created once.
        $fourth = $this->read();
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

        $scan = $this->read();

        $this->assertSame(10, $scan->result->standard);
        $this->assertFalse($scan->testStandard);
    }

    public function test_what_a_hand_reader_types_is_read_even_with_another_keyboard_layout(): void
    {
        $payload = $this->batch->qr_payload;
        $variants = [
            str_replace("\n", "\r\n", $payload),
            str_replace(':', 'Ñ', $payload),
            str_replace('/', '-', $payload),
            str_replace('Lote: ', 'Lote:', $payload),
            $payload."\n",
        ];

        foreach ($variants as $variant) {
            $this->assertSame('33128293', $this->read($variant)->lotCode);
        }

        $this->assertSame(5, PalletScanEvent::query()->count());
    }

    public function test_a_retried_request_or_a_reader_firing_twice_is_not_counted_twice(): void
    {
        $id = (string) Str::uuid();
        $this->read(id: $id);
        $again = app(RegisterManualBoxScan::class)->handle($this->operator, $this->batch->qr_payload, $id, $this->ctx());
        $this->assertSame(ScanOutcome::Duplicate, $again->result->outcome);

        // Same text, new id, less than a second later: the reader fired twice.
        $this->travel(2)->seconds();
        app(RegisterManualBoxScan::class)->handle($this->operator, $this->batch->qr_payload, (string) Str::uuid(), $this->ctx());
        $twice = app(RegisterManualBoxScan::class)->handle($this->operator, $this->batch->qr_payload, (string) Str::uuid(), $this->ctx());
        $this->assertTrue($twice->repeated);

        $this->assertSame(2, PalletScanEvent::query()->count());
    }

    public function test_the_of_on_the_label_is_validated_and_nothing_is_counted_when_it_fails(): void
    {
        $lines = explode("\n", $this->batch->qr_payload);
        $with = function (int $line, string $text) use ($lines): string {
            $lines[$line] = $text;

            return implode("\n", $lines);
        };

        $this->refused('TRZ1:'.str_repeat('a', 32), 'no es una etiqueta de caja');
        $this->refused($with(0, 'OF 99999'), 'La OF 99999 no existe en SAP');
        $this->refused($with(0, 'OF 33129'), 'está «Planificado» en SAP');
        $this->refused($with(1, 'PT1350019'), 'La caja dice PT1350019, pero la OF 33128 es del producto PT1350023');
        $this->refused($with(3, 'Lote: 33128999'), 'El lote 33128999 no tiene etiquetas de caja generadas para la OF 33128');

        $this->assertSame(0, PalletScanEvent::query()->count());
        $this->assertSame(0, Pallet::query()->count());
    }

    public function test_an_of_not_here_yet_is_brought_from_sap_by_its_number(): void
    {
        $this->assertNull(ProductionOrder::query()->where('ref', '33131')->first());
        $lines = explode("\n", $this->batch->qr_payload);
        $lines[0] = 'OF 33131';
        $lines[1] = 'PT1350019';

        // No box labels for that order yet, so the box is refused, but the order was looked up and stored.
        $this->refused(implode("\n", $lines), 'no tiene etiquetas de caja generadas para la OF 33131');
        $this->assertSame(34131, ProductionOrder::query()->where('ref', '33131')->value('sap_doc_entry'));

        $this->sap()->failNextLookups();
        $lines[0] = 'OF 33132';
        $this->refused(implode("\n", $lines), 'No se pudo consultar la OF 33132 en SAP');
    }

    public function test_only_people_who_operate_the_station_read_boxes(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->read(user: $this->userWithRole(Role::LogisticsOperator));
    }

    public function test_the_label_stuck_on_a_pallet_is_confirmed_by_reading_it(): void
    {
        config(['traceability.decisions.label_confirmation' => 'scan']);
        $pallet = Pallet::factory()->palletized()->create();
        app(PrintLabel::class)->handle($this->operator, $pallet->id, null, $this->ctx());
        $confirm = app(ConfirmLabelByScan::class);

        foreach (['', 'TRZ1:'.str_repeat('0', 32), $this->batch->qr_payload] as $wrong) {
            try {
                $confirm->handle($this->operator, $wrong, $this->ctx());
                $this->fail('Debía rechazarse');
            } catch (PalletRuleViolation $e) {
                $this->assertSame('Lo leído no es el QR de una estiba de esta aplicación.', $e->getMessage());
            }
        }

        // Keyboard layout and caps lock on the reader: still this pallet.
        $read = 'TRZ1Ñ'.strtoupper($pallet->qr_token)."\r\n";
        $this->assertSame(PhysicalStatus::Labeled, $confirm->handle($this->operator, $read, $this->ctx())->physical_status);
    }

    public function test_the_station_shows_each_read_and_why_a_box_was_not_counted(): void
    {
        Livewire::actingAs($this->operator)->test('pages::production.station')
            ->assertSee('Lectura manual de cajas')
            ->call('scanBox', $this->batch->qr_payload, (string) Str::uuid())
            ->assertSee('Caja contada')
            ->assertSee('33128293')
            ->assertSee('20/10/2028')
            ->assertSee('Estándar de prueba')
            ->call('scanBox', 'cualquier cosa', (string) Str::uuid())
            ->assertSee('Caja no contada')
            ->assertSee('no es una etiqueta de caja');

        config(['traceability.decisions.label_confirmation' => 'scan']);
        $pallet = Pallet::factory()->palletized()->create();
        app(PrintLabel::class)->handle($this->operator, $pallet->id, null, $this->ctx());

        Livewire::actingAs($this->operator)->test('pages::production.station')
            ->assertSee('Confirmar etiqueta pegada')
            ->call('scanLabel', PalletQr::payload($pallet), (string) Str::uuid())
            ->assertSee("Etiqueta de la estiba {$pallet->pallet_code} confirmada");
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

    public function test_the_box_payload_reader(): void
    {
        $read = BoxLabelPayload::read("OF 33128\nPT1350023\nGELATINA\nLote: 33128293\nVence: 20/10/2028\nContenido: 24 UNIDAD por caja");
        $this->assertSame(['33128', 'PT1350023', '33128293', '2028-10-20'], [$read['order'] ?? null, $read['item'] ?? null, $read['lot'] ?? null, $read['expiration']->toDateString()]);

        $this->assertNull(BoxLabelPayload::read("OF 33128\nPT1350023\nGELATINA\nLote: 33128293"));
        $this->assertNull(BoxLabelPayload::read("OF 33128\nPT1350023\nGELATINA\nLote: 33128293\nVence: 31/02/2028"));
        $this->assertNull(BoxLabelPayload::read("Orden\nPT1350023\nGELATINA\nLote: 33128293\nVence: 20/10/2028"));
    }

    private function sap(): FakeSapGateway
    {
        $sap = app(SapGateway::class);
        $this->assertInstanceOf(FakeSapGateway::class, $sap);

        return $sap;
    }
}
