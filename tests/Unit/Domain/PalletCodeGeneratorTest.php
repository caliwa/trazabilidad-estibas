<?php

namespace Tests\Unit\Domain;

use App\Domain\Pallets\PalletCodeGenerator;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * Without RefreshDatabase on purpose: there is no surrounding transaction.
 */
class PalletCodeGeneratorTest extends TestCase
{
    public function test_numbers_are_only_generated_inside_a_transaction(): void
    {
        $this->assertSame(0, DB::transactionLevel());

        $this->expectException(LogicException::class);
        app(PalletCodeGenerator::class)->next();
    }
}
