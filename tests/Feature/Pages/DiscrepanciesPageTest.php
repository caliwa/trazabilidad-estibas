<?php

namespace Tests\Feature\Pages;

use App\Domain\Audits\Actions\StartBlindCount;
use App\Domain\Audits\Actions\SubmitBlindCount;
use App\Domain\Audits\Enums\DiscrepancyStatus;
use App\Domain\Pallets\PalletQr;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Enums\Role;
use App\Models\DiscrepancyCase;
use App\Models\Pallet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class DiscrepanciesPageTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $admin;

    private Pallet $pallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        Notification::fake();
        $this->admin = $this->userWithRole(Role::TraceabilityAdmin);
        $operator = $this->userWithRole(Role::LogisticsOperator);
        $this->pallet = Pallet::factory()->receivedInSap()->create();
        $ctx = TraceContext::forUser($operator, Origin::Web, (string) Str::uuid());
        $view = app(StartBlindCount::class)->handle($operator, PalletQr::payload($this->pallet), $ctx);
        foreach ([3, 4, 3] as $counted) {
            app(SubmitBlindCount::class)->handle($operator, $view->auditId, $counted, $ctx);
        }
    }

    public function test_admins_see_counted_and_system_quantities_and_the_pending_policy(): void
    {
        config(['traceability.decisions.discrepancy_reaudit_enabled' => false]);
        $this->actingAs($this->admin)->get(route('discrepancies.index'))
            ->assertOk()
            ->assertSee($this->pallet->pallet_code)
            ->assertSee('Resolución pendiente de aprobar (DP-04)')
            ->assertDontSeeHtml("'reaudit')\">");

        foreach ([Role::LogisticsOperator, Role::ProductionOperator, Role::Viewer] as $role) {
            $this->actingAs($this->userWithRole($role))->get(route('discrepancies.index'))->assertForbidden();
        }
    }

    public function test_with_reaudit_enabled_the_admin_authorizes_it_from_the_page(): void
    {
        config(['traceability.decisions.discrepancy_reaudit_enabled' => true]);
        $case = DiscrepancyCase::sole();

        Livewire::actingAs($this->admin)->test('pages::cedi.discrepancies')
            ->assertSeeHtml("'reaudit')\">")
            ->call('open', $case->id, 'reaudit')
            ->call('authorizeReaudit')->assertHasErrors(['notes' => 'required'])
            ->set('notes', 'Recontar con otra persona')->call('authorizeReaudit')->assertHasNoErrors();

        $this->assertSame(DiscrepancyStatus::InReview, $case->refresh()->status);
    }

    public function test_with_close_actions_configured_the_admin_closes_the_case_from_the_page(): void
    {
        config(['traceability.decisions.discrepancy_close_actions' => ['AJUSTE_SAP_REGISTRADO']]);
        $case = DiscrepancyCase::sole();

        Livewire::actingAs($this->admin)->test('pages::cedi.discrepancies')
            ->call('open', $case->id, 'resolve')
            ->set('action', 'AJUSTE_SAP_REGISTRADO')->set('reference', 'DOC-9')->set('notes', 'Ajustado en SAP por inventarios')
            ->call('resolve')->assertHasNoErrors();

        $this->assertSame(DiscrepancyStatus::Resolved, $case->refresh()->status);
    }

    public function test_the_production_supervisor_enables_a_recount_from_the_page(): void
    {
        $supervisor = $this->userWithRole(Role::ProductionSupervisor);
        $case = DiscrepancyCase::sole();

        $this->actingAs($supervisor)->get(route('discrepancies.index'))->assertOk()
            ->assertSee('Habilitar reconteo')
            ->assertSee('3 · 4 · 3')
            ->assertDontSee('Resolución pendiente de aprobar (DP-04)');

        Livewire::actingAs($supervisor)->test('pages::cedi.discrepancies')
            ->call('open', $case->id, 'reaudit')
            ->set('notes', 'Recontar con otro operario')->call('authorizeReaudit')->assertHasNoErrors();

        $this->assertSame(DiscrepancyStatus::InReview, $case->refresh()->status);
        $this->actingAs($supervisor)->get(route('discrepancies.index'))->assertSee('Reconteo habilitado');
    }
}
