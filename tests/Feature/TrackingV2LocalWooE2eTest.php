<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Modules\Logistics\Application\ShipmentService;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\Contracts\WooCommerceTrackingGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * R2-R7 opt-in LOCAL UAT against a standalone localhost WordPress/WooCommerce
 * database. NEVER points at Dev/Production. No real provider egress.
 */
final class TrackingV2LocalWooE2eTest extends TestCase
{
    use RefreshDatabase;

    public function test_erp_shipment_to_local_woo_aftership_and_repeat_safety(): void
    {
        if (getenv('TRACKING_V2_REAL_LOCAL') !== '1') {
            $this->markTestSkipped('Real localhost Woo UAT requires explicit opt-in');
        }
        $base = getenv('HOME').'/AI/uat/tracking-v2-local-wp-20261029/';
        $connection = json_decode(file_get_contents($base.'uat-connection.json'), true, 512, JSON_THROW_ON_ERROR);
        $fixture = json_decode(file_get_contents($base.'r1r7-order.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('http://127.0.0.1:18763', $connection['base_url']);
        self::assertCount(2, $fixture['woo_line_ids']);
        config([
            'services.woocommerce.url' => $connection['base_url'],
            'services.woocommerce.consumer_key' => $connection['consumer_key'],
            'services.woocommerce.consumer_secret' => $connection['consumer_secret'],
            'services.woocommerce.connection_key' => $connection['connection_key'],
            'tracking_sync_v2.manual_mode' => 'canary',
            'tracking_sync_v2.immediate_enabled' => false,
            'tracking_sync_v2.periodic_enabled' => false,
        ]);
        $operator = $this->operator();
        $order = Order::create([
            'internal_order_number' => 'LOCAL-TRACK-R1R7',
            'source_type' => 'woocommerce',
            'order_kind' => 'customer',
            'external_order_number' => 'WC-'.$fixture['order_id'],
            'order_date' => '2026-10-29',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
        $order->externalReferences()->create([
            'provider' => 'woocommerce',
            'connection_key' => $connection['connection_key'],
            'reference' => (string) $fixture['order_id'],
        ]);
        foreach ($fixture['woo_line_ids'] as $i => $id) {
            $order->items()->create([
                'woocommerce_line_item_id' => $id,
                'sku' => 'TRACK-UAT-'.($i + 1),
                'product_summary' => 'Local UAT Sample '.($i + 1),
                'quantity' => 1,
                'unit_price' => '12.00',
                'line_revenue' => '12.00',
            ]);
        }
        $order->financials()->create([
            'currency' => 'USD',
            'provider' => 'woocommerce',
            'provider_reference' => (string) $fixture['order_id'],
            'provider_order_status' => 'processing',
            'provider_modified_at' => now(),
            'payment_method' => 'woocommerce_payments',
            'payment_transaction_id' => 'LOCAL-ONLY-PAID-14',
            'paid_at' => now(),
            'provider_order_total' => '24.00',
            'revenue_provenance' => 'provider_import',
            'completeness_status' => 'INCOMPLETE',
        ]);
        config(['tracking_sync_v2.manual_canary_order_ids' => (string) $order->id]);
        $shipment = app(ShipmentService::class)->markShipped($order, [
            'carrier' => 'USPS',
            'tracking_number' => 'LOCAL-ONLY-TRACK-R1R7-14',
            'shipped_at' => '2026-10-29T12:00:00Z',
        ], $operator);
        $this->assertSame('shipped', $shipment->shipment?->shipment_status);
        $this->assertDatabaseHas('manual_syncs', [
            'order_id' => $order->id, 'website_sync_status' => 'pending',
        ]);
        $gateway = app(WooCommerceTrackingGateway::class);
        $this->assertSame('processing', $gateway->inspectOrderStatus($fixture['order_id']));
        $this->assertFalse($gateway->inspectTracking($fixture['order_id'])['has_tracking']);

        $this->actingAs($operator)->post('/logistics/tracking-outbound/selected', [
            'order_ids' => [$order->id],
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame('completed', $gateway->inspectOrderStatus($fixture['order_id']));
        $tracking = $gateway->inspectTracking($fixture['order_id']);
        $this->assertSame('LOCAL-ONLY-TRACK-R1R7-14', $tracking['tracking_number']);
        $this->assertDatabaseHas('manual_syncs', [
            'order_id' => $order->id, 'website_sync_status' => 'completed',
        ]);
        $this->assertSame(2, DB::table('sync_outbox')->count());

        $this->actingAs($operator)->post('/logistics/tracking-outbound/selected', [
            'order_ids' => [$order->id],
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(2, DB::table('sync_outbox')->count());
        $this->assertSame('completed', $gateway->inspectOrderStatus($fixture['order_id']));
        // Historical completed Outbox must NOT hide a live Processing drift.
        $this->assertTrue($gateway->syncOrderStatus($fixture['order_id'], 'processing')['verified']);
        $this->actingAs($operator)->post('/logistics/tracking-outbound/selected', [
            'order_ids' => [$order->id],
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame('completed', $gateway->inspectOrderStatus($fixture['order_id']));
        $this->assertSame(2, DB::table('sync_outbox')->count());

        // A refund is a protected state: a manual retry MUST NOT resurrect it.
        $this->assertTrue($gateway->syncOrderStatus($fixture['order_id'], 'refunded')['verified']);
        $this->actingAs($operator)->post('/logistics/tracking-outbound/selected', [
            'order_ids' => [$order->id],
        ])->assertRedirect()->assertSessionHas('warning');
        $this->assertSame('refunded', $gateway->inspectOrderStatus($fixture['order_id']));
        $this->assertSame('LOCAL-ONLY-TRACK-R1R7-14', $gateway->inspectTracking($fixture['order_id'])['tracking_number']);
        $this->assertSame(2, DB::table('sync_outbox')->count());
    }

    private function operator(): User
    {
        $role = Role::create(['key' => 'role_preset_operations', 'label' => 'Tracking LOCAL UAT']);
        foreach (['logistics.view', 'orders.view', 'sync.view'] as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user = User::factory()->create(['is_active' => true, 'permission_mode' => 'role']);
        $user->roles()->attach($role);
        return $user;
    }
}
