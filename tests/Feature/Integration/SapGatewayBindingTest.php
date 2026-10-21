<?php

namespace Tests\Feature\Integration;

use App\Integrations\Sap\Contracts\SapGateway;
use App\Integrations\Sap\ServiceLayer\ServiceLayerGateway;
use LogicException;
use Tests\TestCase;

class SapGatewayBindingTest extends TestCase
{
    public function test_the_simulator_is_refused_in_production(): void
    {
        $this->app->forgetInstance(SapGateway::class);
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectExceptionMessage('El simulador de SAP no se puede usar en producción.');
        app(SapGateway::class);
    }

    public function test_service_layer_binds_the_real_connector_and_an_unknown_driver_is_refused(): void
    {
        $this->app->forgetInstance(SapGateway::class);
        config(['sap.driver' => 'service_layer', 'sap.service_layer' => ['base_url' => 'https://sap.test/b1s/v1', 'company_db' => 'SBO', 'username' => 'u', 'password' => 'p']]);
        $this->assertInstanceOf(ServiceLayerGateway::class, app(SapGateway::class));

        $this->app->forgetInstance(SapGateway::class);
        config(['sap.driver' => 'otro']);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Conector SAP desconocido');
        app(SapGateway::class);
    }
}
