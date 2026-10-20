<?php

namespace Tests\Feature\Security;

use App\Models\BoxConversionFactor;
use App\Models\Device;
use App\Models\DiscrepancyCase;
use App\Models\IntegrationAttempt;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferPallet;
use App\Models\Item;
use App\Models\Pallet;
use App\Models\PalletAudit;
use App\Models\PalletLabelPrint;
use App\Models\PalletScanEvent;
use App\Models\PalletStandard;
use App\Models\ProductionOrder;
use App\Models\ProductionReceipt;
use App\Models\ProductionReceiptPallet;
use App\Models\SapSyncRun;
use App\Models\TraceEvent;
use App\Support\Logging\StructuredLogging;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * Section 12: internal errors are never shown, domain models accept no
 * mass assignment, baseline headers, and nothing private is reachable
 * under public/.
 */
class HardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_errors_are_not_exposed_in_html_or_json(): void
    {
        config(['app.debug' => false]);
        $secret = 'SQLSTATE[42P01] relation "pallets" password=s3cr3t at /var/www/app/Secret.php';
        Route::get('/_boom', fn () => throw new RuntimeException($secret))->middleware('web');
        Route::get('/api/_boom', fn () => throw new RuntimeException($secret));

        $html = $this->get('/_boom')->assertStatus(500)->getContent();
        $json = $this->getJson('/api/_boom')->assertStatus(500)->getContent();

        foreach ([$html, $json] as $body) {
            $this->assertStringNotContainsString('SQLSTATE', (string) $body);
            $this->assertStringNotContainsString('s3cr3t', (string) $body);
            $this->assertStringNotContainsString('Secret.php', (string) $body);
        }
        $this->assertSame(['message' => 'Server Error'], json_decode((string) $json, true));
    }

    public function test_domain_models_ignore_mass_assignment(): void
    {
        $models = [
            Pallet::class, PalletScanEvent::class, PalletAudit::class, PalletLabelPrint::class, DiscrepancyCase::class,
            ProductionReceipt::class, ProductionReceiptPallet::class, InventoryTransfer::class, InventoryTransferPallet::class,
            IntegrationAttempt::class, TraceEvent::class, Device::class, Item::class, ProductionOrder::class,
            PalletStandard::class, BoxConversionFactor::class, SapSyncRun::class,
        ];

        foreach ($models as $class) {
            /** @var Model $model */
            $model = new $class;
            $defaults = $model->getAttributes();

            try {
                $model->fill(['id' => 99, 'boxes_count' => 999, 'status' => 'CONFIRMADO', 'token_hash' => 'x', 'user_id' => 1]);
            } catch (MassAssignmentException) {
                // Outside production unknown keys even throw.
            }

            $this->assertSame($defaults, $model->getAttributes(), "{$class} acepta asignación masiva");
        }
    }

    public function test_responses_carry_the_baseline_security_headers(): void
    {
        $response = $this->get(route('login'));

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_log_lines_are_json_with_the_correlation_id_and_no_secrets(): void
    {
        $path = storage_path('logs/structured-test.log');
        @unlink($path);
        config(['logging.channels.structured_test' => ['driver' => 'single', 'path' => $path, 'tap' => [StructuredLogging::class]]]);
        $log = Log::channel('structured_test');
        Log::shareContext(['correlation_id' => 'corr-123']);

        $log->warning('sap.call', ['password' => 'p4ss', 'headers' => ['Cookie' => 'B1SESSION=abc', 'Authorization' => 'Bearer t0k'], 'doc' => 77]);

        $line = json_decode(trim((string) file_get_contents($path)), true, flags: JSON_THROW_ON_ERROR);
        @unlink($path);

        $this->assertSame('sap.call', $line['message']);
        $this->assertSame('corr-123', $line['context']['correlation_id'] ?? $line['extra']['correlation_id'] ?? null);
        $this->assertSame(77, $line['context']['doc']);
        $encoded = json_encode($line);
        foreach (['p4ss', 'B1SESSION=abc', 't0k'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $encoded);
        }
    }

    public function test_nothing_private_lives_under_the_web_root(): void
    {
        foreach (['.env', '.env.example', 'composer.json', 'artisan', 'storage', 'vendor', '.git'] as $private) {
            $this->assertFileDoesNotExist(public_path($private));
        }
    }
}
