<?php

namespace Tests\Feature\Integration;

use App\Integrations\Sap\Contracts\SapGateway;
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

    public function test_the_real_connector_does_not_exist_until_sap_is_verified(): void
    {
        $this->app->forgetInstance(SapGateway::class);
        config(['sap.driver' => 'service_layer']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('DP-09');
        app(SapGateway::class);
    }
}
