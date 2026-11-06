<?php

namespace Tests\Feature;

use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\Contracts\WooCommerceTrackingGateway;
use App\Modules\Sync\Application\WooCommerceManualTrackingOverrideService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Opt-in actual LOCAL WordPress + AfterShip UAT. Never Dev or www.
 * Fixture's must-use plugin blocks external HTTP, mail and webhooks.
 */
final class TrackingManualOverrideLocalWooE2eTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_a_child_without_line_identity_publishes_to_real_woo_mother_and_completed(): void
    {
        if (getenv('TRACKING_MANUAL_REAL_LOCAL') !== '1') {
            $this->markTestSkipped('Explicit standalone LOCAL Woo/AfterShip manual test only');
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
            'tracking_sync_v2.immediate_enabled' => false,
            'tracking_sync_v2.periodic_enabled' => false,
        ]);

        $root = Order::create([
            'internal_order_number' => 'LOCAL-V31-ROOT',
            'source_type' => 'woocommerce',
            'order_kind' => 'customer',
            'external_order_number' => 'WC-'.$fixture['order_id'],
            'order_date' => '2026-11-06',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
        $root->externalReferences()->create([
            'provider' => 'woocommerce',
            'connection_key' => $connection['connection_key'],
            'reference' => (string) $fixture['order_id'],
        ]);
        $child = Order::create([
            'fulfillment_parent_id' => $root->id,
            'fulfillment_suffix' => 'A',
            'internal_order_number' => 'LOCAL-V31-A',
            'source_type' => 'manual_other',
            'order_kind' => 'fulfillment_derived',
            'order_date' => '2026-11-06',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
        $child->items()->create([
            'sku' => 'V31-TEST-A',
            'product_summary' => 'Local-only test child A',
            'quantity' => 1,
            'unit_price' => '12.00',
            'line_revenue' => '12.00',
        ]);
        $child->shipment()->create([
            'shipment_status' => 'pending',
            'carrier' => getenv('TRACKING_MANUAL_LOCAL_NO_CARRIER') === '1' ? null : 'USPS',
            'tracking_number' => 'LOCAL-ONLY-V31-CHILD-A',
        ]);

        $gateway = app(WooCommerceTrackingGateway::class);
        self::assertSame('processing', $gateway->inspectOrderStatus((int) $fixture['order_id']));
        self::assertFalse($gateway->inspectTracking((int) $fixture['order_id'])['has_tracking']);

        $service = app(WooCommerceManualTrackingOverrideService::class);
        self::assertSame('completed', $service->pushOne($child, null)['status']);
        self::assertSame('completed', $gateway->inspectOrderStatus((int) $fixture['order_id']));
        self::assertSame('LOCAL-ONLY-V31-CHILD-A', $gateway->inspectTracking((int) $fixture['order_id'])['tracking_number']);
        self::assertSame(
            getenv('TRACKING_MANUAL_LOCAL_NO_CARRIER') === '1' ? 'other' : 'usps',
            $gateway->inspectTracking((int) $fixture['order_id'])['provider'],
        );
        $this->assertDatabaseHas('order_operational_markers', [
            'order_id' => $root->id, 'marker' => 'woo_manual_force_completed',
        ]);
        $this->assertDatabaseHas('manual_syncs', [
            'order_id' => $child->id, 'website_sync_status' => 'completed',
        ]);
        self::assertSame('pending', $child->shipment->fresh()->shipment_status);
        self::assertFalse($service->pushOne($child, null)['written']);

        // A second split child appends its package to the SAME Woo mother
        // without removing A or recreating the Woo order.
        $childB = Order::create([
            'fulfillment_parent_id' => $root->id,
            'fulfillment_suffix' => 'B',
            'internal_order_number' => 'LOCAL-V31-B',
            'source_type' => 'manual_other',
            'order_kind' => 'fulfillment_derived',
            'order_date' => '2026-11-06',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
        $childB->items()->create([
            'sku' => 'V31-TEST-B',
            'product_summary' => 'Local-only test child B',
            'quantity' => 1,
            'unit_price' => '12.00',
            'line_revenue' => '12.00',
        ]);
        $childB->shipment()->create([
            'shipment_status' => 'pending',
            'carrier' => 'USPS',
            'tracking_number' => 'LOCAL-ONLY-V31-CHILD-B',
        ]);
        self::assertSame('completed', $service->pushOne($childB, null)['status']);
        self::assertSame('completed', $gateway->inspectOrderStatus((int) $fixture['order_id']));
        $remote = Http::withBasicAuth(
            $connection['consumer_key'], $connection['consumer_secret']
        )->get($connection['base_url'].'/wp-json/wc/v3/orders/'.$fixture['order_id'])->throw()->json();
        $packages = collect($remote['meta_data'] ?? [])
            ->firstWhere('key', '_aftership_tracking_items')['value'] ?? [];
        self::assertCount(2, $packages);
        self::assertSame(
            ['LOCAL-ONLY-V31-CHILD-A', 'LOCAL-ONLY-V31-CHILD-B'],
            array_column($packages, 'tracking_number'),
        );
        $this->assertDatabaseHas('manual_syncs', [
            'order_id' => $childB->id, 'website_sync_status' => 'completed',
        ]);
    }
}
