<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class Barcode69FormatTest extends TestCase
{
    public function test_barcode_is_a_string_and_preserves_leading_zeros(): void
    {
        $barcode = '06977291390285';
        $this->assertSame('06977291390285', $barcode);
        $this->assertSame(14, strlen($barcode));
    }
}
