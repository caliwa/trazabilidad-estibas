<?php

namespace Tests\Concerns;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL aborts the whole transaction after a failed statement. Running
 * the attempt inside a nested transaction (a savepoint) keeps the test's
 * RefreshDatabase transaction usable afterwards.
 */
trait AssertsDatabaseRejects
{
    protected function assertDatabaseRejects(callable $attempt, string $expectedMessage): void
    {
        try {
            DB::transaction(fn () => $attempt());
        } catch (QueryException $e) {
            $this->assertStringContainsString($expectedMessage, $e->getMessage());

            return;
        }

        $this->fail("La base de datos aceptó una operación que debía rechazar ({$expectedMessage}).");
    }
}
