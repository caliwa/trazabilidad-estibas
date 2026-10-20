<?php

namespace Tests\Feature\Operations;

use App\Domain\Operations\Heartbeat;
use App\Jobs\RecordWorkerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class HealthAndPreflightTest extends TestCase
{
    use RefreshDatabase;

    private function alive(): void
    {
        Heartbeat::record(Heartbeat::SCHEDULER);
        RecordWorkerHeartbeat::dispatchSync('sap');
        RecordWorkerHeartbeat::dispatchSync('default');
    }

    public function test_health_is_ok_only_when_database_scheduler_and_workers_are_alive(): void
    {
        $this->alive();
        $this->getJson(route('health'))->assertOk()->assertExactJson([
            'status' => 'ok',
            'checks' => ['database' => 'ok', 'scheduler' => 'ok', 'worker_sap' => 'ok', 'worker_default' => 'ok'],
        ]);

        $this->travel(config()->integer('operations.heartbeat_max_age_minutes') + 1)->minutes();
        $this->getJson(route('health'))->assertStatus(503)->assertJsonPath('status', 'fail')->assertJsonPath('checks.worker_sap', 'fail');
    }

    public function test_health_says_nothing_about_internals(): void
    {
        $body = $this->getJson(route('health'))->getContent();

        foreach ([config('database.connections.pgsql.database'), config('database.connections.pgsql.host'), PHP_VERSION, 'Laravel', 'exception'] as $secret) {
            $this->assertStringNotContainsString((string) $secret, (string) $body);
        }
    }

    public function test_preflight_fails_on_an_unsafe_configuration(): void
    {
        config(['app.debug' => true, 'sap.driver' => 'fake', 'session.secure' => false, 'queue.default' => 'sync']);

        $this->assertSame(1, Artisan::call('app:preflight'));
        $output = Artisan::output();

        $this->assertStringContainsString('APP_DEBUG=false', $output);
        $this->assertStringContainsString('FALLA', $output);
        $this->assertStringContainsString('No activar', $output);
        $this->assertStringContainsString('DP-05 traslado agrupado', $output);
    }

    public function test_preflight_passes_a_safe_configuration(): void
    {
        $this->app['env'] = 'production';
        config([
            'app.debug' => false, 'app.url' => 'https://estibas.example.com', 'session.secure' => true, 'session.http_only' => true,
            'session.same_site' => 'lax', 'queue.default' => 'database', 'cache.default' => 'database', 'sap.driver' => 'service_layer',
        ]);

        $code = Artisan::call('app:preflight');
        $output = Artisan::output();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Listo para activar', $output);
    }
}
