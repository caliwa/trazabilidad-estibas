<?php

namespace Tests\Feature\Pallets;

use App\Domain\Pallets\Actions\ConfirmLabeled;
use App\Domain\Pallets\Actions\ConfirmPalletized;
use App\Domain\Pallets\Actions\PrintLabel;
use App\Domain\Pallets\Enums\LabelPrintKind;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Pallets\Exceptions\PalletRuleViolation;
use App\Domain\Pallets\PalletQr;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Enums\Role;
use App\Models\Pallet;
use App\Models\PalletLabelPrint;
use App\Models\TraceEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * CA-08 (no palletizing in process, no labeling without palletizing),
 * CA-09 (reprint keeps identity and quantity), CA-10 (QR cannot bypass the
 * server), RF-07 to RF-09.
 */
class PalletizeAndLabelTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->operator = $this->userWithRole(Role::ProductionOperator);
    }

    private function ctx(?User $user = null): TraceContext
    {
        return TraceContext::forUser($user ?? $this->operator, Origin::Web, (string) Str::uuid());
    }

    private function palletize(Pallet $pallet, ?User $user = null): Pallet
    {
        return app(ConfirmPalletized::class)->handle($user ?? $this->operator, $pallet->id, $this->ctx($user));
    }

    private function print(Pallet $pallet, ?string $reason = null, ?User $user = null): PalletLabelPrint
    {
        return app(PrintLabel::class)->handle($user ?? $this->operator, $pallet->id, $reason, $this->ctx($user));
    }

    private function confirm(Pallet $pallet, ?string $qr = null): Pallet
    {
        return app(ConfirmLabeled::class)->handle($this->operator, $pallet->id, $qr, $this->ctx());
    }

    public function test_a_finished_pallet_is_palletized_with_who_and_when(): void
    {
        $pallet = $this->palletize(Pallet::factory()->finished()->create());

        $this->assertSame(PhysicalStatus::Palletized, $pallet->physical_status);
        $this->assertSame($this->operator->id, $pallet->palletized_by);
        $this->assertSame('pallet.palletized', TraceEvent::sole()->event);
    }

    public function test_a_pallet_in_process_cannot_be_palletized_nor_palletized_twice(): void
    {
        foreach ([Pallet::factory()->withBoxes(3)->create(), Pallet::factory()->palletized()->create()] as $pallet) {
            try {
                $this->palletize($pallet);
                $this->fail('Debía rechazarse');
            } catch (PalletRuleViolation) {
            }
        }

        $this->assertSame(0, TraceEvent::count());
    }

    public function test_a_label_needs_a_palletized_pallet(): void
    {
        $this->expectExceptionMessage('no está paletizada');

        $this->print(Pallet::factory()->finished()->create());
    }

    public function test_the_first_print_is_original_and_a_reprint_needs_a_reason_and_keeps_identity_and_quantity(): void
    {
        $pallet = Pallet::factory()->palletized()->create();
        $before = $pallet->only(['pallet_code', 'qr_token', 'boxes_count', 'lot_code', 'production_order_ref']);

        $first = $this->print($pallet);
        $this->assertSame(LabelPrintKind::Original, $first->kind);

        try {
            $this->print($pallet);
            $this->fail('La reimpresión sin motivo debía rechazarse');
        } catch (PalletRuleViolation $e) {
            $this->assertStringContainsString('motivo', $e->getMessage());
        }

        $second = $this->print($pallet, 'Etiqueta rota');

        $this->assertSame([2, LabelPrintKind::Reprint, 'Etiqueta rota'], [$second->print_number, $second->kind, $second->reason]);
        $pallet->refresh();
        $this->assertSame(2, $pallet->label_print_count);
        $this->assertSame($before, $pallet->only(array_keys($before)));
        $this->assertSame(1, Pallet::count());
        $this->assertSame(['label.printed', 'label.reprinted'], TraceEvent::where('event', 'like', 'label.%')->orderBy('id')->pluck('event')->all());
    }

    public function test_reprinting_needs_its_own_permission(): void
    {
        $pallet = Pallet::factory()->palletized()->create();
        $this->print($pallet);

        $labeler = $this->userWithRole(Role::ProductionOperator);
        $labeler->roles()->detach();
        $labeler->givePermissionTo('pallets.label');

        $this->expectException(AuthorizationException::class);
        $this->print($pallet, 'Motivo', $labeler);
    }

    public function test_label_confirmation_is_refused_while_dp03_is_open(): void
    {
        config(['traceability.decisions.label_confirmation' => null]);
        $pallet = Pallet::factory()->palletized()->create();
        $this->print($pallet);

        $this->expectExceptionMessage('DP-03');
        $this->confirm($pallet);
    }

    public function test_with_button_mode_the_operator_confirms_after_printing(): void
    {
        config(['traceability.decisions.label_confirmation' => 'button']);
        $pallet = Pallet::factory()->palletized()->create();

        try {
            $this->confirm($pallet);
            $this->fail('Sin etiqueta impresa no se confirma');
        } catch (PalletRuleViolation $e) {
            $this->assertStringContainsString('no se ha impreso', $e->getMessage());
        }

        $this->print($pallet);
        $pallet = $this->confirm($pallet);

        $this->assertSame(PhysicalStatus::Labeled, $pallet->physical_status);
        $this->assertSame($this->operator->id, $pallet->labeled_by);
        $this->assertSame(['confirmation' => 'button', 'physical_status' => 'ETIQUETADO'], collect(TraceEvent::where('event', 'pallet.labeled')->sole()->to_state)->sortKeys()->all());
    }

    public function test_with_scan_mode_only_this_pallets_qr_confirms_it(): void
    {
        config(['traceability.decisions.label_confirmation' => 'scan']);
        $pallet = Pallet::factory()->palletized()->create();
        $other = Pallet::factory()->palletized()->create();
        $this->print($pallet);

        foreach ([null, '', PalletQr::payload($other), $pallet->qr_token, PalletQr::PREFIX.strtoupper($pallet->qr_token), PalletQr::PREFIX.$pallet->qr_token."' OR 1=1"] as $tampered) {
            try {
                $this->confirm($pallet, $tampered);
                $this->fail('Debía rechazar '.var_export($tampered, true));
            } catch (PalletRuleViolation $e) {
                $this->assertSame('El código leído no corresponde a esta estiba.', $e->getMessage());
            }
        }

        $this->assertSame(PhysicalStatus::Labeled, $this->confirm($pallet, ' '.PalletQr::payload($pallet)."\n")->physical_status);
    }

    public function test_with_print_mode_the_first_print_labels_the_pallet_and_confirming_is_not_needed(): void
    {
        config(['traceability.decisions.label_confirmation' => 'print']);
        $pallet = Pallet::factory()->palletized()->create();

        $this->print($pallet);

        $this->assertSame(PhysicalStatus::Labeled, $pallet->refresh()->physical_status);
        $this->expectExceptionMessage('se da por aplicada al imprimirla');
        $this->confirm($pallet);
    }

    public function test_the_qr_carries_only_the_opaque_token(): void
    {
        $pallet = Pallet::factory()->withBoxes(7)->create();

        $payload = PalletQr::payload($pallet);

        // Prefix + 32 random hex characters: no id, code, lot, order or quantity fits in there.
        $this->assertMatchesRegularExpression('/^TRZ1:[a-f0-9]{32}$/', $payload);
        $this->assertSame($pallet->qr_token, PalletQr::tokenFrom($payload));
        $this->assertNull(PalletQr::tokenFrom($pallet->pallet_code));
        $this->assertStringStartsWith('<?xml', PalletQr::svg($pallet));
    }

    public function test_operators_without_permission_are_refused_on_the_server(): void
    {
        $pallet = Pallet::factory()->finished()->create();

        $this->expectException(AuthorizationException::class);
        $this->palletize($pallet, $this->userWithRole(Role::LogisticsOperator));
    }
}
