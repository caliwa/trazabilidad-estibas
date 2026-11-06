<?php

namespace Tests\Feature;

use App\Domain\Pallets\Enums\ScanEventStatus;
use App\Enums\Role;
use App\Models\Pallet;
use App\Models\PalletScanEvent;
use App\Models\User;
use App\Queries\DashboardQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The home page: supervisors (dashboard.kpis) get the management
 * indicators; everyone else, their role as before.
 */
class DashboardTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 15:30'));
    }

    /** Boxes read into $pallet at $at. */
    private function reads(Pallet $pallet, int $boxes, string $at): void
    {
        PalletScanEvent::factory()->count($boxes)->create([
            'pallet_id' => $pallet->id,
            'processing_status' => ScanEventStatus::Accepted,
            'rejection_reason' => null,
            'processed_at' => CarbonImmutable::parse($at),
            'received_at' => CarbonImmutable::parse($at),
        ]);
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk();
    }

    public function test_only_supervisors_see_the_indicators(): void
    {
        foreach ([Role::ProductionSupervisor, Role::Admin] as $role) {
            $this->actingAs($this->userWithRole($role))->get(route('dashboard'))->assertOk()
                ->assertSee('data-dashboard-kpis', false)->assertSee('Tablero de gestión')->assertSee('Flujo de estibas');
        }

        // The others keep the page as it was; the logistics operator never sees box counts (blind count).
        foreach ([Role::ProductionOperator, Role::LogisticsOperator, Role::Quality, Role::Viewer, Role::TraceabilityAdmin] as $role) {
            $this->actingAs($this->userWithRole($role))->get(route('dashboard'))->assertOk()
                ->assertDontSee('data-dashboard-kpis', false)->assertSee('Tu rol');
        }
    }

    public function test_today_is_compared_with_yesterday_at_the_same_time(): void
    {
        $pallet = Pallet::factory()->create();
        $this->reads($pallet, 30, '2026-10-20 08:10');
        $this->reads($pallet, 12, '2026-10-20 15:05');
        $this->reads($pallet, 20, '2026-10-19 09:00');
        // Yesterday after 15:30 does not count against today so far.
        $this->reads($pallet, 50, '2026-10-19 17:00');

        $p = app(DashboardQuery::class)->production();

        $this->assertSame([42, 20, 12], [$p['boxes'], $p['boxes_before'], $p['last_hour']]);
        // From the first box of the day (08:10) to now: 42 boxes in 7 h 20 min.
        $this->assertSame(6, $p['hourly_average']);
        $hours = collect($p['hours'])->keyBy('hour');
        $this->assertSame([30, 0], [$hours[8]['today'], $hours[8]['yesterday']]);
        $this->assertSame([12, 0], [$hours[15]['today'], $hours[15]['yesterday']]);
        $this->assertSame(20, $hours[9]['yesterday']);
        $this->assertSame(15, collect($p['hours'])->last()['hour']);
        $this->assertCount(14, $p['days']);
        $this->assertSame([70, 42], [$p['days'][12]['boxes'], $p['days'][13]['boxes']]);
    }

    public function test_the_flow_shows_where_the_pallets_are_and_the_bottleneck(): void
    {
        Pallet::factory()->create();
        Pallet::factory()->finished()->count(3)->create();
        Pallet::factory()->palletized()->create();
        Pallet::factory()->labeled()->create();

        $flow = collect(app(DashboardQuery::class)->flow())->keyBy('key');

        $this->assertSame([1, 3, 1, 1, 0], [$flow['stacking']['count'], $flow['finished']['count'], $flow['palletized']['count'], $flow['labeled']['count'], $flow['count']['count']]);
        $this->assertNotNull($flow['finished']['oldest']);

        Livewire::actingAs($this->userWithRole(Role::ProductionSupervisor))->test('pages::dashboard')
            ->assertSeeHtml('data-bottleneck="finished"')
            ->assertSee('Cuello de botella: Por paletizar');
    }
}
