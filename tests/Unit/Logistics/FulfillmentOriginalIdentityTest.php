<?php

namespace Tests\Unit\Logistics;

use App\Modules\Logistics\Application\FulfillmentOriginalIdentity as Identity;
use DomainException;
use PHPUnit\Framework\TestCase;

final class FulfillmentOriginalIdentityTest extends TestCase
{
    public function test_first_generation_keeps_existing_suffixes(): void
    {
        self::assertSame(1, Identity::forAllocation([], [], 2));
        self::assertSame('A', Identity::suffix(0, 1));
        self::assertSame('B', Identity::suffix(1, 1));
        self::assertSame(1, Identity::index('B'));
    }

    public function test_historical_originals_are_not_reused(): void
    {
        self::assertSame(2, Identity::forAllocation([], ['A', 'B', 'R1'], 2));
        self::assertSame('A-2', Identity::suffix(0, 2));
        self::assertSame('B-2', Identity::suffix(1, 2));
        self::assertSame(1, Identity::index('B-2'));
        self::assertSame(3, Identity::forAllocation([], ['A', 'B', 'A-2', 'R2'], 2));
        self::assertNull(Identity::generation('R2'));
    }

    public function test_pending_edits_keep_current_generation(): void
    {
        self::assertSame(2, Identity::forAllocation(['A-2', 'B-2'], ['A', 'B', 'A-2', 'B-2'], 3));
    }

    public function test_historical_slot_cannot_be_recycled_in_current_generation(): void
    {
        $this->expectException(DomainException::class);
        Identity::forAllocation(['A-2'], ['A', 'B', 'A-2', 'B-2'], 2);
    }

    public function test_mixed_active_generations_fail_closed(): void
    {
        $this->expectException(DomainException::class);
        Identity::forAllocation(['A', 'B-2'], ['A', 'B-2'], 2);
    }

    public function test_generation_exhaustion_fails_closed(): void
    {
        $this->expectException(DomainException::class);
        Identity::forAllocation([], ['A-99'], 1);
    }
}
