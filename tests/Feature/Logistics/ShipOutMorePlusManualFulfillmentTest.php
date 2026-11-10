<?php

namespace Tests\Feature\Logistics;

use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Models\Carrier;
use App\Modules\Logistics\Infrastructure\Models\CarrierService;
use App\Modules\Logistics\Infrastructure\Models\OverseaWarehouseReview;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Sync\Application\Contracts\WooCommerceTrackingGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ShipOutMorePlusManualFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    private function prepare(): array
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::query()->create(['key' => 'test-moreplus-dede-'.uniqid(), 'label' => 'Dede Logistics Test']);
        foreach (['logistics.view', 'logistics.operate'] as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user->roles()->attach($role);
        $warehouse = Warehouse::query()->firstOrCreate(['code' => 'US-LA'], [
            'slug' => 'us-la', 'name' => 'MorePlus US-LA',
            'type' => 'warehouse', 'is_active' => true, 'priority' => 10,
        ]);
        $carrier = Carrier::query()->firstOrCreate(['name' => 'USPS'], ['name_en' => 'USPS', 'is_active' => true]);
        $service = CarrierService::query()->firstOrCreate([
            'carrier_id' => $carrier->id, 'service_key' => 'priority',
        ], [
            'service_value' => 'Priority', 'name_en' => 'Priority', 'is_active' => true, 'sort_order' => 1,
        ]);
        $carrier->update(['default_service_id' => $service->id]);
        $product = Product::query()->create([
            'sku' => 'MOREPLUS-MANUAL-TEST', 'name' => 'QA product',
            'product_type' => 'simple', 'is_active' => true,
        ]);
        $order = Order::query()->create([
            'internal_order_number' => 'MANUAL-QA-1',
            'erp_order_id' => '007-1110-c42141',
            'source_type' => 'manual_other',
            'order_kind' => 'customer',
            'external_order_number' => 'SYNTHETIC-MANUAL-1',
            'order_date' => now()->toDateString(),
            'order_status' => 'pending_shipment',
        ]);
        $order->address()->create([
            'recipient_name' => 'Synthetic QA', 'country' => 'US',
            'province_state' => 'CA', 'city' => 'Test',
            'address_line_1' => '100 Test Rd', 'postal_code' => '90210',
            'phone' => '5550199',
        ]);
        $order->items()->create([
            'product_id' => $product->id, 'sku' => 'MOREPLUS-MANUAL-TEST',
            'product_summary' => 'QA product', 'quantity' => 1,
            'unit_price' => '12.00', 'line_revenue' => '12.00',
        ]);
        $order->shipment()->create([
            'warehouse_id' => $warehouse->id, 'shipment_status' => 'pending',
            'warehouse_selection_source' => 'manual', 'carrier' => 'USPS',
        ]);
        OverseaWarehouseReview::query()->create([
            'order_id' => $order->id,
            'submitted_warehouse_id' => $warehouse->id,
            'reviewed_warehouse_id' => $warehouse->id,
            'reviewed_by' => $user->id, 'reviewed_at' => now(),
            'status' => 'approved',
        ]);

        return [$user, $order];
    }

    private function attempt(Order $order): void
    {
        DB::table('shipout_dispatch_attempts')->insert([
            'order_id' => $order->id,
            'order_no' => 'C42141-007',
            'provider_warehouse_id' => '1265864414750314497',
            'payload_hash' => str_repeat('a', 64),
            'status' => 'acknowledged',
            'provider_order_id' => 'OMS-EXISTING-ONE-ONLY',
            'result_code' => 'ASYNC_ACCEPTED_QUERY_REQUIRED',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function data(int $id): array
    {
        return [
            'tracking_number' => '9400111899223856928501',
            'outbound_reference' => 'MP-MANUAL-OUT-001',
            'confirmation' => 'CONFIRM_MOREPLUS_MANUAL_SHIPPED_'.$id,
        ];
    }

    public function test_dede_manual_completion_uses_real_tracking_and_queues_website_sync_without_any_provider_post(): void
    {
        [$user, $order] = $this->prepare();
        $this->attempt($order);
        $order->shipment()->update(['logistics_note' => 'Dede 原始审核备注']);
        Http::preventStrayRequests();
        $this->actingAs($user)->post('/logistics/oversea/us-moreplus/orders/'.$order->id.'/manual-ship',
            $this->data($order->id))->assertRedirect('/logistics/oversea/us-moreplus?view=shipped');
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'shipment_status' => 'shipped',
            'tracking_number' => '9400111899223856928501',
        ]);
        $this->assertDatabaseHas('oversea_warehouse_reviews', [
            'order_id' => $order->id, 'progress' => 'shipped',
        ]);
        $this->assertSame('pending', $order->fresh()->manualSync?->website_sync_status?->value);
        $this->assertDatabaseCount('shipout_dispatch_attempts', 1);
        $this->assertStringContainsString('MP-MANUAL-OUT-001',
            (string) $order->fresh()->shipment->logistics_note);
        $this->assertStringContainsString('Dede 原始审核备注',
            (string) $order->fresh()->shipment->logistics_note);
        Http::assertNothingSent();
    }

    public function test_manual_woo_confirmation_queues_outbox_for_periodic_sync_only(): void
    {
        [$user, $order] = $this->prepare();
        $this->attempt($order);
        $order->forceFill([
            'source_type' => 'woocommerce',
            'external_order_number' => 'WC-99001',
        ])->save();
        $order->externalReferences()->create([
            'provider' => 'woocommerce', 'connection_key' => 'shop-a', 'reference' => '99001',
        ]);
        // The actual fulfillment payment gate must remain enforced for Woo
        // orders; our synthetic fixture supplies real paid-provider semantics.
        $order->financials()->create([
            'currency' => 'USD',
            'provider' => 'woocommerce',
            'provider_reference' => '99001',
            'provider_order_status' => 'processing',
            'provider_modified_at' => now(),
            'payment_method' => 'woocommerce_payments',
            'payment_transaction_id' => 'SYNTHETIC-R9-PAY-99001',
            'paid_at' => now(),
            'provider_order_total' => '12.00',
            'revenue_provenance' => 'provider_import',
            'completeness_status' => 'INCOMPLETE',
        ]);
        $order->items()->first()->forceFill(['woocommerce_line_item_id' => 990011])->save();
        config([
            'services.woocommerce.url' => 'https://shop.test',
            'services.woocommerce.consumer_key' => 'ck_test',
            'services.woocommerce.consumer_secret' => 'cs_test',
            'services.woocommerce.connection_key' => 'shop-a',
        ]);
        $gateway = \Mockery::mock(WooCommerceTrackingGateway::class);
        $gateway->shouldReceive('descriptor')->andReturn([
            'configured' => true, 'url' => 'https://shop.test', 'connection_key' => 'shop-a',
        ]);
        // No expectation permitting syncTracking or syncOrderStatus: any
        // immediate Woo call would fail this test and never be sent.
        $this->app->instance(WooCommerceTrackingGateway::class, $gateway);

        Http::preventStrayRequests();
        $this->actingAs($user)->post('/logistics/oversea/us-moreplus/orders/'.$order->id.'/manual-ship',
            $this->data($order->id))->assertRedirect('/logistics/oversea/us-moreplus?view=shipped');

        $this->assertDatabaseHas('sync_outbox', [
            'operation' => 'woocommerce.tracking_outbound',
            'aggregate_id' => (string) $order->fresh()->shipment->id,
            'status' => 'pending', 'attempts' => 0,
        ]);
        $this->assertDatabaseHas('manual_syncs', [
            'order_id' => $order->id, 'website_sync_status' => 'pending',
        ]);
        $this->assertDatabaseCount('shipout_dispatch_attempts', 1);
        Http::assertNothingSent();
    }

    public function test_duplicate_provider_order_is_not_eligible_for_manual_reshipment(): void
    {
        [$user, $order] = $this->prepare();
        $this->attempt($order);
        $bid = '00000000-0000-4000-8000-000000000133';
        DB::table('shipout_production_batches')->insert([
            'id' => $bid, 'operator_user_id' => $user->id,
            'status' => 'completed_partial', 'dry_run' => false, 'total' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('shipout_production_batch_items')->insert([
            'batch_id' => $bid, 'order_id' => $order->id,
            'order_no' => $order->erp_order_id, 'status' => 'provider_cancelled',
            'result_code' => 'PROVIDER_DUPLICATE_ORDER',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Http::preventStrayRequests();
        $this->actingAs($user)->post('/logistics/oversea/us-moreplus/orders/'.$order->id.'/manual-ship',
            $this->data($order->id))->assertStatus(409);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id, 'shipment_status' => 'pending', 'tracking_number' => null,
        ]);
        Http::assertNothingSent();
    }

    public function test_direct_single_order_oms_duplicate_readback_blocks_manual_fulfillment(): void
    {
        [$user, $order] = $this->prepare();
        $this->attempt($order);
        DB::table('shipout_oms_readbacks')->insert([
            'order_id' => $order->id,
            'provider_order_id' => 'OMS-EXISTING-ONE-ONLY',
            'provider_status' => 6, 'state' => 'cancelled',
            'reason_code' => 'PROVIDER_DUPLICATE_ORDER',
            'checked_at' => now(), 'created_at' => now(),
        ]);
        Http::preventStrayRequests();
        $this->actingAs($user)
            ->post('/logistics/oversea/us-moreplus/orders/'.$order->id.'/manual-ship',
                $this->data($order->id))
            ->assertStatus(409);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id, 'shipment_status' => 'pending',
            'tracking_number' => null,
        ]);
        $this->assertDatabaseCount('shipout_dispatch_attempts', 1);
        Http::assertNothingSent();
    }

    public function test_manual_confirm_without_existing_attempt_is_blocked(): void
    {
        [$user, $order] = $this->prepare();
        Http::preventStrayRequests();
        $this->actingAs($user)->post('/logistics/oversea/us-moreplus/orders/'.$order->id.'/manual-ship',
            $this->data($order->id))->assertStatus(409);
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_manual_confirm_requires_explicit_confirmation_and_outbound_evidence(): void
    {
        [$user, $order] = $this->prepare();
        $this->attempt($order);
        Http::preventStrayRequests();
        $data = $this->data($order->id);
        $data['confirmation'] = 'NO';
        $this->actingAs($user)->post('/logistics/oversea/us-moreplus/orders/'.$order->id.'/manual-ship',
            $data)->assertStatus(422);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id, 'shipment_status' => 'pending',
        ]);
        Http::assertNothingSent();
    }

    private function setupVerifiedAutoShip(Order $order): void
    {
        $this->attempt($order);
        DB::table('shipout_oms_readbacks')->insert([
            'order_id' => $order->id,
            'provider_order_id' => 'OMS-EXISTING-ONE-ONLY',
            'provider_status' => 4,
            'state' => 'provider_shipped',
            'reason_code' => null,
            'checked_at' => now(),
            'created_at' => now(),
        ]);
        config()->set('shipout.production_read_enabled', true);
        config()->set('shipout.production_app_key', 'fake-app-key');
        config()->set('shipout.production_app_secret', 'fake-secret');
        config()->set('shipout.production_bearer', 'bearer fake-token');
        config()->set('shipout.production_warehouse_id', '1265864414750314497');
        config()->set('shipout.production_client_org_id', '57893');
        config()->set('shipout.production_v1_batch_enabled', false);
    }

    public function test_json_tracking_save_auto_completes_only_after_exact_signed_oms_shipped_proof(): void
    {
        [$user, $order] = $this->prepare();
        $this->setupVerifiedAutoShip($order);
        $tracking = '9434650105794040132223';
        $time = now('UTC')->subHours(2)->format('Y-m-d H:i:s');
        Http::preventStrayRequests();
        Http::fake(['https://open.shipout.com/api/*' => Http::response([
            'result' => 'OK',
            'data' => [
                'orderId' => 'OMS-EXISTING-ONE-ONLY', 'orderNO' => 'C42141-007',
                'status' => 4, 'warehouseId' => '1265864414750314497',
                'orderRecipient' => ['phone' => 'PRIVATE_DO_NOT_LEAK'],
                'orderShipments' => [[
                    'outboundNO' => 'OUTBOUND-123', 'outboundStatus' => 4,
                    'trackingNumber' => $tracking, 'shippedTime' => $time,
                    'packageList' => [['trackingNumber' => $tracking]],
                ]],
            ],
        ], 200)]);
        $this->withSession(['_token' => str_repeat('c', 40)])
            ->withHeader('X-CSRF-TOKEN', str_repeat('c', 40));
        $oldEnv = $this->app['env'];
        $this->app->instance('env', 'production');
        try {
            $this->actingAs($user)
                ->patchJson("/logistics/oversea/orders/{$order->id}/draft", [
                    'tracking_number' => $tracking,
                ])->assertOk()
                ->assertJsonPath('order_id', $order->id)
                ->assertJsonPath('shipment_status', 'shipped')
                ->assertJsonPath('auto_shipped', true)
                ->assertJsonPath('tracking_number', $tracking)
                ->assertJsonPath('website_sync', 'not_required');
        } finally {
            $this->app->instance('env', $oldEnv);
        }
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id, 'shipment_status' => 'shipped',
            'tracking_number' => $tracking,
        ]);
        $this->assertDatabaseHas('oversea_warehouse_reviews', [
            'order_id' => $order->id, 'progress' => 'shipped',
        ]);
        $this->assertSame('shipped', $order->fresh()->order_status->value);
        $this->assertDatabaseCount('shipout_dispatch_attempts', 1);
        $this->assertSame($time, $order->fresh()->shipment->shipped_at->utc()->format('Y-m-d H:i:s'));
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_tracking_saved_but_provider_not_shipped_does_not_auto_advance_or_sync(): void
    {
        [$user, $order] = $this->prepare();
        $this->setupVerifiedAutoShip($order);
        $tracking = '9434650105794040132223';
        Http::preventStrayRequests();
        Http::fake(['https://open.shipout.com/api/*' => Http::response([
            'result' => 'OK',
            'data' => [
                'orderId' => 'OMS-EXISTING-ONE-ONLY',
                'orderNO' => 'C42141-007',
                'status' => 2,
                'orderShipments' => [[
                    'outboundStatus' => 0,
                    'trackingNumber' => null,
                    'shippedTime' => null,
                    'packageList' => [],
                ]],
            ],
        ], 200)]);
        $this->withSession(['_token' => str_repeat('c', 40)])
            ->withHeader('X-CSRF-TOKEN', str_repeat('c', 40));
        $oldEnv = $this->app['env'];
        $this->app->instance('env', 'production');
        try {
            $this->actingAs($user)
                ->patchJson("/logistics/oversea/orders/{$order->id}/draft", [
                    'tracking_number' => $tracking,
                ])->assertOk()
                ->assertJsonPath('shipment_status', 'pending')
                ->assertJsonPath('auto_shipped', false)
                ->assertJsonPath('tracking_number', $tracking);
        } finally {
            $this->app->instance('env', $oldEnv);
        }
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id, 'shipment_status' => 'pending',
            'tracking_number' => $tracking,
        ]);
        $this->assertDatabaseCount('shipout_dispatch_attempts', 1);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_plain_json_remark_save_returns_one_row_and_does_not_query_oms(): void
    {
        [$user, $order] = $this->prepare();
        Http::preventStrayRequests();
        $this->actingAs($user)
            ->patchJson("/logistics/oversea/orders/{$order->id}/draft", [
                'note' => '仓库正在处理中',
            ])->assertOk()
            ->assertJsonPath('note', '仓库正在处理中')
            ->assertJsonPath('auto_shipped', false)
            ->assertJsonPath('shipment_status', 'pending');
        $this->assertDatabaseHas('oversea_warehouse_reviews', [
            'order_id' => $order->id, 'note' => '仓库正在处理中',
        ]);
        Http::assertNothingSent();
    }
}
