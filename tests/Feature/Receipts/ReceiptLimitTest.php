<?php

namespace Tests\Feature\Receipts;

use App\Domain\Orders\Actions\UnlockOrderReceipts;
use App\Domain\Receipts\Exceptions\ReceiptRuleViolation;
use App\Domain\Settings\AppSettings;
use App\Domain\Settings\Setting;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\ProductionOrder;
use App\Models\TraceEvent;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\BuildsReceipts;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * An order takes production receipts until what is received (or on its way)
 * reaches the limit percentage of the planned quantity; then it has to be
 * closed in SAP, unless the production supervisor lets it go on.
 *
 * Each labeled pallet here is 40 boxes x 12 = 480 units; the order plans
 * 2000, so the 90 % limit is 1800.
 */
class ReceiptLimitTest extends TestCase
{
    use BuildsReceipts, CreatesUsers, RefreshDatabase;

    private User $requester;

    private ProductionOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seedRoles();
        $this->requester = $this->userWithRole(Role::TraceabilityAdmin);
        $this->order = ProductionOrder::factory()->create(['planned_quantity' => '2000', 'sap_status' => 'LIBERADO']);
    }

    private function ctx(User $user): TraceContext
    {
        return TraceContext::forUser($user, Origin::Web, (string) Str::uuid());
    }

    private function refusedAtLimit(callable $request, string $limit = '90'): void
    {
        try {
            $request();
            $this->fail('La OF debía estar bloqueada por el límite de recibos.');
        } catch (ReceiptRuleViolation $e) {
            $this->assertStringContainsString("llegó al {$limit} % de lo planificado", $e->getMessage());
            $this->assertStringContainsString('cerrar la OF en SAP', $e->getMessage());
        }
    }

    public function test_the_receipt_that_crosses_the_limit_goes_and_then_the_order_is_blocked(): void
    {
        $pallets = $this->labeledPallets(['L1', 'L1', 'L1', 'L1', 'L1'], '12', $this->order);

        $this->requestReceipts($this->requester, $pallets->take(3));
        $this->assertSame(0, TraceEvent::query()->where('event', 'production_order.receipt_limit_reached')->count());

        // 1440 of 2000 (72 %): one more pallet takes it to 1920 (96 %); it goes.
        $this->requestReceipts($this->requester, [$pallets[3]]);
        $reached = TraceEvent::query()->where('event', 'production_order.receipt_limit_reached')->sole();
        $this->assertStringContainsString('Hay que cerrarla en SAP', (string) $reached->reason);

        $this->refusedAtLimit(fn () => $this->requestReceipts($this->requester, [$pallets[4]]));
        $this->assertSame(1, TraceEvent::query()->where('event', 'production_order.receipt_limit_reached')->count());
    }

    public function test_the_limit_is_a_parameter_changed_by_the_admin_and_audited(): void
    {
        $admin = $this->userWithRole(Role::Admin);
        app(AppSettings::class)->set($admin, Setting::ReceiptLimitPercent, 50);
        $pallets = $this->labeledPallets(['L1', 'L1', 'L1', 'L1'], '12', $this->order);

        // 960 of 2000 is 48 %; the third pallet crosses 50 % and goes; the fourth does not.
        $this->requestReceipts($this->requester, $pallets->take(2));
        $this->requestReceipts($this->requester, [$pallets[2]]);
        $this->refusedAtLimit(fn () => $this->requestReceipts($this->requester, [$pallets[3]]), '50');

        $log = AuditLog::query()->where('action', 'setting.updated')->sole();
        $this->assertSame(['receipts.order_limit_percent' => 90], $log->old_values);
        $this->assertSame(['receipts.order_limit_percent' => 50], $log->new_values);
        $this->assertSame($admin->id, $log->user_id);

        $this->expectException(AuthorizationException::class);
        app(AppSettings::class)->set($this->userWithRole(Role::ProductionSupervisor), Setting::ReceiptLimitPercent, 80);
    }

    public function test_the_supervisor_unlocks_the_order_and_it_takes_receipts_until_closed_in_sap(): void
    {
        $pallets = $this->labeledPallets(['L1', 'L1', 'L1', 'L1', 'L1', 'L1'], '12', $this->order);
        $this->requestReceipts($this->requester, $pallets->take(4));
        $supervisor = $this->userWithRole(Role::ProductionSupervisor);

        $this->order->refresh();
        app(UnlockOrderReceipts::class)->handle($supervisor, $this->order->id, 'Se siguen produciendo cajas del pedido', $this->ctx($supervisor));

        $this->order->refresh();
        $this->assertSame($supervisor->id, $this->order->receipts_unlocked_by);
        $this->assertNotNull($this->order->receipts_unlocked_at);
        $trace = TraceEvent::query()->where('event', 'production_order.receipts_unlocked')->sole();
        $this->assertSame('Se siguen produciendo cajas del pedido', $trace->reason);
        $this->assertSame($supervisor->id, $trace->user_id);

        // Past the plan and still going: no new limit for this order.
        $this->requestReceipts($this->requester, [$pallets[4]]);
        $this->requestReceipts($this->requester, [$pallets[5]]);
        $this->assertSame(6, $this->order->pallets()->where('receipt_status', 'SOLICITADO')->count());
    }

    public function test_only_a_blocked_order_is_unlocked_with_a_reason_by_whoever_has_the_permission(): void
    {
        $supervisor = $this->userWithRole(Role::ProductionSupervisor);

        try {
            app(UnlockOrderReceipts::class)->handle($supervisor, $this->order->id, 'x', $this->ctx($supervisor));
            $this->fail('No hay nada que habilitar.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('no ha llegado al límite', $e->getMessage());
        }

        $this->requestReceipts($this->requester, $this->labeledPallets(['L1', 'L1', 'L1', 'L1'], '12', $this->order));

        try {
            app(UnlockOrderReceipts::class)->handle($supervisor, $this->order->id, '  ', $this->ctx($supervisor));
            $this->fail('El motivo es obligatorio.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('por qué', $e->getMessage());
        }

        foreach ([Role::TraceabilityAdmin, Role::ProductionOperator, Role::Viewer] as $role) {
            try {
                app(UnlockOrderReceipts::class)->handle($this->userWithRole($role), $this->order->id, 'x', $this->ctx($supervisor));
                $this->fail("{$role->value} no debe poder habilitar recibos.");
            } catch (AuthorizationException) {
            }
        }

        $this->assertNull($this->order->refresh()->receipts_unlocked_at);
    }

    public function test_an_order_without_planned_quantity_has_no_limit(): void
    {
        $order = ProductionOrder::factory()->create(['planned_quantity' => null]);
        $pallets = $this->labeledPallets(['L1', 'L1'], '12', $order);

        $this->requestReceipts($this->requester, [$pallets[0]]);
        $this->requestReceipts($this->requester, [$pallets[1]]);

        $this->assertSame(0, TraceEvent::query()->where('event', 'production_order.receipt_limit_reached')->count());
    }

    public function test_the_pages_warn_block_and_let_the_supervisor_unlock(): void
    {
        $pallets = $this->labeledPallets(['L1', 'L1', 'L1', 'L1', 'L1'], '12', $this->order);
        $this->requestReceipts($this->requester, $pallets->take(3));

        $receipts = Livewire::actingAs($this->requester)->test('pages::receipts.index')
            ->set('orderId', $this->order->id)
            ->assertSee('Lleva 72 % de lo planificado')
            ->set('selected', [$pallets[3]->id])
            ->call('request')
            ->assertSee('llegó al 90 % de lo planificado: hay que cerrarla en SAP');
        $receipts->set('selected', [$pallets[4]->id])->call('request');
        $this->assertSame('SIN_RECIBO', $pallets[4]->refresh()->receipt_status->value);

        $supervisor = $this->userWithRole(Role::ProductionSupervisor);
        Livewire::actingAs($this->userWithRole(Role::ProductionOperator))->test('pages::orders.index')->assertForbidden();
        Livewire::actingAs($this->userWithRole(Role::TraceabilityAdmin))->test('pages::orders.index')
            ->assertSee('Recibos bloqueados')->assertSee('cerrar la OF en SAP')
            ->assertDontSee('Habilitar recibos');

        Livewire::actingAs($supervisor)->test('pages::orders.index')
            ->assertSee('Recibos bloqueados')->assertSee('cerrar la OF en SAP')
            ->call('askUnlock', $this->order->id)
            ->call('unlock')
            ->assertHasErrors(['unlockReason' => 'required'])
            ->set('unlockReason', 'Pedido adicional del cliente')
            ->call('unlock')
            ->assertHasNoErrors()
            ->assertSee('Recibos habilitados después del 90 %');

        $this->assertNotNull($this->order->refresh()->receipts_unlocked_at);
    }

    public function test_the_admin_changes_the_limit_from_the_parameters_page(): void
    {
        $admin = $this->userWithRole(Role::Admin);
        $this->actingAs($this->userWithRole(Role::ProductionSupervisor))->get(route('admin.settings'))->assertForbidden();

        Livewire::actingAs($admin)->test('pages::admin.settings')
            ->assertSet('values.ReceiptLimitPercent', 90)
            ->set('values.ReceiptLimitPercent', 0)
            ->call('save')
            ->assertHasErrors()
            ->set('values.ReceiptLimitPercent', 85)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(85, app(AppSettings::class)->int(Setting::ReceiptLimitPercent));
        $this->actingAs($admin)->get(route('admin.settings'))->assertOk()->assertSee('Límite de recibos por OF');
    }
}
