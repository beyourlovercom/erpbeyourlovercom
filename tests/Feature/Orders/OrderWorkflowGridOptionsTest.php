<?php

namespace Tests\Feature\Orders;

use App\Modules\Orders\Application\OrderWorkflowGridOptions;
use PHPUnit\Framework\TestCase;

final class OrderWorkflowGridOptionsTest extends TestCase
{
    public function test_global_grid_exposes_only_safe_customer_status_commands_as_mutable(): void
    {
        $options = collect(OrderWorkflowGridOptions::customer())->keyBy('code');

        foreach (['pending_allocation', 'pending_review', 'sending', 'finished', 'shipped', 'completed', 'missing_info', 'renew', 'returned_follow_up', 'claims', 'freight_product_cost_follow_up', 'exception', 'out_of_stock', 'cancelled'] as $code) {
            self::assertTrue($options->get($code)['mutable'], $code);
        }

        foreach (['allocated', 'shipping', 'archived', 'progress_conflict', 'published', 'refunded'] as $code) {
            self::assertFalse($options->get($code)['mutable'], $code);
        }

        self::assertSame('待分仓', $options->get('pending_allocation')['zh']);
        self::assertSame('信息缺失', $options->get('missing_info')['zh']);
    }
}
