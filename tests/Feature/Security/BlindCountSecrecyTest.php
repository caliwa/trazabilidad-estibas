<?php

namespace Tests\Feature\Security;

use App\Domain\Pallets\PalletQr;
use App\Enums\Role;
use App\Models\Pallet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * CA-15: before submitting, the operator cannot get the expected quantity
 * through the page, the Livewire state, the responses, the errors or any
 * other endpoint. Hiding a column is not enough; these tests read what is
 * actually sent to the browser.
 */
class BlindCountSecrecyTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    /** Unusual on purpose, so finding it anywhere is a leak and not a coincidence. */
    private const SECRET = 8861;

    private User $operator;

    private Pallet $pallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->operator = $this->userWithRole(Role::LogisticsOperator);
        $this->pallet = Pallet::factory()->received()->create(['standard_boxes_snapshot' => self::SECRET]);
        $this->assertSame(self::SECRET, $this->pallet->refresh()->boxes_count);
    }

    private function assertNoLeak(string $where, string $content): void
    {
        $this->assertDoesNotMatchRegularExpression('/(?<![\w.])'.self::SECRET.'(?![\w.])/', $content, "La cantidad esperada aparece en {$where}");
    }

    /**
     * Everything that reaches the browser from a Livewire test: HTML, snapshot and effects.
     */
    private function assertComponentDoesNotLeak(Testable $component, string $step): void
    {
        $this->assertNoLeak("el HTML ({$step})", $component->html());
        $this->assertNoLeak("el snapshot ({$step})", (string) json_encode($component->snapshot));
        $this->assertNoLeak("los effects ({$step})", (string) json_encode($component->effects));
    }

    public function test_the_page_state_and_responses_never_carry_the_expected_quantity(): void
    {
        $page = Livewire::actingAs($this->operator)->test('pages::cedi.count');
        $this->assertComponentDoesNotLeak($page, 'inicio');

        $page->set('scanned', PalletQr::payload($this->pallet))->call('scan')->assertSee($this->pallet->pallet_code);
        $this->assertComponentDoesNotLeak($page, 'después de leer la etiqueta');

        $page->set('counted', 'abc')->call('submit')->assertHasErrors('counted');
        $this->assertComponentDoesNotLeak($page, 'error de validación');

        $page->set('counted', 100000)->call('submit')->assertHasErrors('counted');
        $this->assertComponentDoesNotLeak($page, 'fuera de rango');

        Notification::fake();
        $page->set('counted', 12)->call('submit')->assertSee('Diferencia registrada');
        $this->assertComponentDoesNotLeak($page, 'resultado con diferencia');
    }

    public function test_the_full_http_page_does_not_carry_it_either(): void
    {
        $this->assertNoLeak('la página completa', (string) $this->actingAs($this->operator)->get(route('cedi.count'))->assertOk()->getContent());
    }

    public function test_the_count_in_progress_cannot_be_swapped_from_the_browser(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($this->operator)->test('pages::cedi.count')->set('auditId', 1);
    }

    public function test_no_other_screen_or_endpoint_shows_quantities_to_the_operator(): void
    {
        $this->actingAs($this->operator);

        foreach ([
            route('pallets.index'), route('pallets.show', $this->pallet), route('pallets.rejected'),
            route('pallets.label', ['pallet' => $this->pallet->id, 'print' => 1]), route('production.station'),
            route('receipts.index'), route('discrepancies.index'), route('admin.audit'),
        ] as $url) {
            $response = $this->get($url);
            $this->assertSame(403, $response->status(), "{$url} debía estar prohibida");
            $this->assertNoLeak($url, (string) $response->getContent());
        }
    }

    public function test_the_operator_never_receives_the_discrepancy_notification(): void
    {
        Notification::fake();

        $page = Livewire::actingAs($this->operator)->test('pages::cedi.count')
            ->set('scanned', PalletQr::payload($this->pallet))->call('scan')
            ->set('counted', 1)->call('submit');

        Notification::assertNothingSentTo($this->operator);
        $this->assertComponentDoesNotLeak($page, 'notificación');
    }
}
