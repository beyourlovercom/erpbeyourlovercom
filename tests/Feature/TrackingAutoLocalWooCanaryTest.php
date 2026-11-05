<?php

namespace Tests\Feature;

use App\Modules\Logistics\Application\LogisticsTrackingImmediateDispatch;
use App\Modules\Logistics\Application\ShipmentService;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\Contracts\WooCommerceTrackingGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Explicit opt-in only, on standalone 127.0.0.1 WordPress + Woo + AfterShip.
 * The local WP fixture blocks mail, webhooks and non-local egress.
 */
final class TrackingAutoLocalWooCanaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_order_canary_pushes_once_and_remote_woo_readback_is_verified(): void
    {
        if (getenv('TRACKING_AUTO_REAL_LOCAL') !== '1') {
            $this->markTestSkipped('Explicit standalone LOCAL Woo Canary test only');
        }

        $base = getenv('HOME').'/AI/uat/tracking-v2-local-wp-20261029/';
        $conn = json_decode(file_get_contents($base.'uat-connection.json'), true, 512, JSON_THROW_ON_ERROR);
        $fixture = json_decode(file_get_contents($base.'r1r7-order.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('http://127.0.0.1:18763', $conn['base_url']);
        self::assertCount(2, $fixture['woo_line_ids']);

        config([
            'services.woocommerce.url' => $conn['base_url'],
            'services.woocommerce.consumer_key' => $conn['consumer_key'],
            'services.woocommerce.consumer_secret' => $conn['consumer_secret'],
            'services.woocommerce.connection_key' => $conn['connection_key'],
            'tracking_sync_v2.immediate_enabled' => true,
            'tracking_sync_v2.immediate_mode' => 'canary',
            'tracking_sync_v2.immediate_canary_order_ids' => '',
            'tracking_sync_v2.periodic_enabled' => false,
        ]);

        $order = Order::create([
            'internal_order_number' => 'LOCAL-AUTO-CANARY-R3',
            'source_type' => 'woocommerce',
            'order_kind' => 'customer',
            'external_order_number' => 'WC-'.$fixture['order_id'],
            'order_date' => '2026-11-05',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
        $order->externalReferences()->create([
            'provider' => 'woocommerce',
            'connection_key' => $conn['connection_key'],
            'reference' => (string) $fixture['order_id'],
        ]);
        foreach ($fixture['woo_line_ids'] as $index => $lineId) {
            $order->items()->create([
                'woocommerce_line_item_id' => $lineId,
                'sku' => 'AUTO-CANARY-R3-'.($index + 1),
                'product_summary' => 'Local Canary Fixture '.($index + 1),
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
            'payment_transaction_id' => 'LOCAL-AUTO-ONLY-PAID',
            'paid_at' => now(),
            'provider_order_total' => '24.00',
            'revenue_provenance' => 'provider_import',
            'completeness_status' => 'INCOMPLETE',
        ]);

        $shipment = app(ShipmentService::class)->markShipped($order, [
            'carrier' => 'USPS',
            'tracking_number' => 'LOCAL-ONLY-AUTO-CANARY-R3',
            'shipped_at' => '2026-11-05T03:00:00Z',
        ], null);
        self::assertSame('shipped', $shipment->shipment?->shipment_status);

        $gateway = app(WooCommerceTrackingGateway::class);
        $dispatch = app(LogisticsTrackingImmediateDispatch::class);
        self::assertSame('processing', $gateway->inspectOrderStatus((int) $fixture['order_id']));
        self::assertFalse($gateway->inspectTracking((int) $fixture['order_id'])['has_tracking']);
        self::assertSame('not_allowlisted', $dispatch->afterShipmentSaved($order, null)['status']);
        config(['tracking_sync_v2.immediate_canary_order_ids' => '999999']);
        self::assertSame('not_allowlisted', $dispatch->afterShipmentSaved($order, null)['status']);
        self::assertSame(0, DB::table('sync_outbox')->count());

        config(['tracking_sync_v2.immediate_canary_order_ids' => (string) $order->getKey()]);
        self::assertSame('completed', $dispatch->afterShipmentSaved($order, null)['status']);
        self::assertSame('completed', $gateway->inspectOrderStatus((int) $fixture['order_id']));
        self::assertSame('LOCAL-ONLY-AUTO-CANARY-R3', $gateway->inspectTracking((int) $fixture['order_id'])['tracking_number']);
        $this->assertDatabaseHas('manual_syncs', [
            'order_id' => $order->getKey(), 'website_sync_status' => 'completed',
        ]);
        self::assertSame(2, DB::table('sync_outbox')->count());

        self::assertSame('completed', $dispatch->afterShipmentSaved($order, null)['status']);
        self::assertSame(2, DB::table('sync_outbox')->count());
    }
}
