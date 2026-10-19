<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Str;
use Tests\TestCase;

class CorrelationIdTest extends TestCase
{
    public function test_every_response_carries_a_correlation_id(): void
    {
        $id = $this->get('/up')->assertOk()->headers->get('X-Correlation-Id');

        $this->assertTrue(Str::isUuid((string) $id));
    }

    public function test_a_valid_caller_id_is_kept_and_anything_else_is_replaced(): void
    {
        $mine = (string) Str::uuid();

        $this->get('/up', ['X-Correlation-Id' => $mine])->assertHeader('X-Correlation-Id', $mine);

        $replaced = $this->get('/up', ['X-Correlation-Id' => "'; DROP TABLE pallets; --"])->headers->get('X-Correlation-Id');
        $this->assertTrue(Str::isUuid((string) $replaced));
    }
}
