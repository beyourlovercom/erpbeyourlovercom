<?php

namespace Tests\Feature;

use App\Modules\Orders\Application\OrderIdentityService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WooOrderIdentityRepairCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_command_previews_then_applies_exact_date_bound_identity_correction(): void
    {
        app(OrderIdentityService::class)->updatePrefix('007', null);
        $order = Order::create([
            'internal_order_number' => 'WEB-TEST-40414',
            'erp_order_id' => '007-0910-40414',
            'source_type' => 'woocommerce',
            'order_kind' => 'customer',
            'external_order_number' => '40414',
            'order_date' => '2026-09-10',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
        $order->externalReferences()->create([
            'provider' => 'woocommerce',
            'connection_key' => 'shop-a',
            'reference' => '40414',
        ]);

        $this->artisan('orders:repair-woo-identities --date=2026-09-10 --json')
            ->assertSuccessful();
        $this->assertSame('007-0910-40414', $order->fresh()->erp_order_id);

        $this->artisan('orders:repair-woo-identities --date=2026-09-10 --apply --expect-change=1 --json')
            ->assertSuccessful();

        $this->assertSame('007-0910-c40414', $order->fresh()->erp_order_id);
        $this->assertDatabaseHas('audit_records', [
            'action' => 'order.erp_identity_repaired',
            'entity_id' => (string) $order->id,
            'reason' => 'owner_rule_007_mmdd_c_woo_number',
        ]);
    }

    public function test_apply_requires_exact_expected_change_count(): void
    {
        app(OrderIdentityService::class)->updatePrefix('007', null);
        $order = Order::create([
            'internal_order_number' => 'WEB-TEST-40415',
            'erp_order_id' => '007-0910-c6108',
            'source_type' => 'woocommerce',
            'order_kind' => 'customer',
            'external_order_number' => '40415',
            'order_date' => '2026-09-10',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
        $order->externalReferences()->create([
            'provider' => 'woocommerce',
            'connection_key' => 'shop-a',
            'reference' => '40415',
        ]);

        $this->artisan('orders:repair-woo-identities --date=2026-09-10 --apply --expect-change=2 --json')
            ->assertFailed();
        $this->assertSame('007-0910-c6108', $order->fresh()->erp_order_id);
    }
}
