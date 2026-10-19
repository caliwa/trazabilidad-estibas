<?php

namespace Tests\Unit\Domain;

use App\Support\Logging\Redactor;
use PHPUnit\Framework\TestCase;

class RedactorTest extends TestCase
{
    public function test_secret_keys_are_masked_at_any_depth_and_the_rest_is_kept(): void
    {
        $redacted = Redactor::redact([
            'UserName' => 'manager',
            'Password' => 'secreto',
            'headers' => ['Cookie' => 'B1SESSION=abc; ROUTEID=.node1', 'Authorization' => 'Basic xyz'],
            'B1SESSION' => 'abc',
            'api_key' => 'k',
            'lines' => [['ItemCode' => 'PT-1', 'Quantity' => '480']],
        ]);

        $this->assertSame('manager', $redacted['UserName']);
        $this->assertSame(Redactor::MASK, $redacted['Password']);
        $this->assertSame(['Cookie' => Redactor::MASK, 'Authorization' => Redactor::MASK], $redacted['headers']);
        $this->assertSame(Redactor::MASK, $redacted['B1SESSION']);
        $this->assertSame(Redactor::MASK, $redacted['api_key']);
        $this->assertSame([['ItemCode' => 'PT-1', 'Quantity' => '480']], $redacted['lines']);
    }
}
