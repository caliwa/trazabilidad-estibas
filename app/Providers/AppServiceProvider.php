<?php

namespace App\Providers;

use App\Http\Middleware\AuthenticateDevice;
use App\Integrations\Sap\Contracts\SapGateway;
use App\Integrations\Sap\Fake\FakeSapGateway;
use App\Models\BoxConversionFactor;
use App\Models\Device;
use App\Models\DiscrepancyCase;
use App\Models\InventoryTransfer;
use App\Models\Item;
use App\Models\Pallet;
use App\Models\PalletAudit;
use App\Models\PalletScanEvent;
use App\Models\PalletStandard;
use App\Models\ProductionOrder;
use App\Models\ProductionReceipt;
use App\Models\User;
use App\Support\Correlation\CorrelationId;
use App\Support\Database\PostgresConnection;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CorrelationId::class);

        // Laravel's default is timestamp(0): whole seconds, rounded. Scan events need their order within a second.
        SchemaBuilder::defaultTimePrecision(6);

        Connection::resolverFor('pgsql', fn ($pdo, string $database, string $prefix, array $config) => new PostgresConnection($pdo, $database, $prefix, $config));

        $this->app->singleton(SapGateway::class, function ($app): SapGateway {
            $driver = config()->string('sap.driver');

            if ($driver === 'fake') {
                if ($app->isProduction()) {
                    throw new LogicException('El simulador de SAP no se puede usar en producción.');
                }

                return new FakeSapGateway;
            }

            throw new LogicException("El conector SAP [{$driver}] no está implementado. Ver DP-09.");
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // `composer dev`: the SAP jobs live on their own queue.
        DevCommands::artisan('queue:listen --queue=sap,default --tries=1 --timeout=0', 'queue');

        // Per device: a line reads a box every few seconds; this leaves room for bursts and retries.
        RateLimiter::for('scan-events', function (Request $request): Limit {
            $device = $request->attributes->get(AuthenticateDevice::ATTRIBUTE);

            return Limit::perMinute(config()->integer('traceability.scan_rate_per_minute'))
                ->by($device instanceof Device ? 'device:'.$device->id : 'ip:'.$request->ip());
        });

        // Short, stable aliases for polymorphic columns (trace_events, audit_logs).
        Relation::enforceMorphMap([
            'user' => User::class,
            'device' => Device::class,
            'item' => Item::class,
            'production_order' => ProductionOrder::class,
            'pallet_standard' => PalletStandard::class,
            'box_conversion_factor' => BoxConversionFactor::class,
            'pallet' => Pallet::class,
            'pallet_scan_event' => PalletScanEvent::class,
            'pallet_audit' => PalletAudit::class,
            'discrepancy_case' => DiscrepancyCase::class,
            'production_receipt' => ProductionReceipt::class,
            'inventory_transfer' => InventoryTransfer::class,
        ]);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
