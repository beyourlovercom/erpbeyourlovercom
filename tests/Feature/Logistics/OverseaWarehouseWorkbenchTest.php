<?php

namespace Tests\Feature\Logistics;

use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\InventorySyncBatch;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Logistics\Infrastructure\Models\OverseaWarehouseReview;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Sync\Application\Contracts\WooCommerceTrackingGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class OverseaWarehouseWorkbenchTest extends TestCase
{
    use RefreshDatabase;

    public function test_warehouse_page_is_isolated_and_uses_frozen_carrier_mapping(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $ny = $this->orderAt($warehouses['US-NY'], '007-OVERSEA-NY');
        $this->orderAt($warehouses['DE'], '007-OVERSEA-DE', 'DE');

        $this->actingAs($user)->get('/logistics/oversea/us-ny?view=review')->assertInertia(fn (Assert $page) => $page
            ->component('Logistics/Oversea/Show')
            ->where('warehouse.label', '美东纽约仓')
            ->where('rows.total', 1)
            ->where('rows.data.0.order_id', $ny->id)
            ->where('rows.data.0.line', 1)
            ->where('rows.data.0.carrier', 'USPS')
            ->where('rows.data.0.review_status', 'pending'));
    }


    public function test_three_sm_uses_canonical_us_3sm_url_and_legacy_keys_still_resolve(): void
    {
        $user = $this->userWith(['logistics.view']);
        $warehouses = $this->warehouses();
        $this->orderAt($warehouses['US-SM'], '007-OVERSEA-SJ');

        $this->actingAs($user)->get('/logistics/oversea/us-3sm')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('warehouseKey', 'us-3sm')
            ->where('warehouse.code', 'US-SM')
            ->where('warehouse.label', '美国3世迈仓'));
        $this->actingAs($user)->get('/logistics/oversea/us-sm')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('warehouseKey', 'us-3sm')
            ->where('warehouse.code', 'US-SM'));
    }

    public function test_approval_moves_order_from_review_to_pending_and_persists_default_carrier(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['DE'], '007-OVERSEA-APPROVE', 'DE');

        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();

        $this->assertDatabaseHas('oversea_warehouse_reviews', [
            'order_id' => $order->id,
            'status' => 'approved',
            'reviewed_warehouse_id' => $warehouses['DE']->id,
        ]);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'warehouse_id' => $warehouses['DE']->id,
            'carrier' => 'DHL',
            'shipment_status' => 'pending',
        ]);

        $this->actingAs($user)->get('/logistics/oversea/de-tianyun?view=pending')->assertInertia(
            fn (Assert $page) => $page->where('rows.total', 1)->where('rows.data.0.order_id', $order->id)
        );
        $this->actingAs($user)->get('/logistics/oversea/de-tianyun?view=review')->assertInertia(
            fn (Assert $page) => $page->where('rows.total', 0)
        );
    }

    public function test_approved_order_can_be_reopened_for_review_and_reassignment(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007-OVERSEA-REOPEN');

        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve", ['note' => '审核通过'])->assertRedirect();
        $response = $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/reopen-review");
        $response->assertRedirect('/logistics/oversea/us-moreplus?view=review');

        $this->assertDatabaseHas('oversea_warehouse_reviews', [
            'order_id' => $order->id, 'status' => 'pending', 'progress' => 'pending_review',
            'reviewed_warehouse_id' => null, 'reviewed_by' => null, 'reviewed_at' => null,
        ]);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id, 'warehouse_id' => $warehouses['US-LA']->id, 'carrier' => 'USPS',
        ]);
    }

    public function test_dede_can_change_warehouse_and_carrier_follows_the_new_warehouse(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007-OVERSEA-CHANGE');

        $this->actingAs($user)->patch("/logistics/oversea/orders/{$order->id}/draft", [
            'warehouse_id' => $warehouses['CA']->id,
            'note' => 'Vita 仓库选择错误',
        ])->assertRedirect();

        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'warehouse_id' => $warehouses['CA']->id,
            'carrier' => 'Canada Post',
        ]);
        $this->assertDatabaseHas('oversea_warehouse_reviews', [
            'order_id' => $order->id,
            'submitted_warehouse_id' => $warehouses['US-LA']->id,
            'status' => 'pending',
            'note' => 'Vita 仓库选择错误',
        ]);
    }

    public function test_manual_carrier_override_survives_approval(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007-OVERSEA-MANUAL-CARRIER');

        $this->actingAs($user)->patch("/logistics/oversea/orders/{$order->id}/draft", [
            'carrier' => 'FedEx',
        ])->assertRedirect();
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id, 'carrier' => 'FedEx', 'carrier_selection_source' => 'manual',
        ]);

        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id, 'carrier' => 'FedEx', 'carrier_selection_source' => 'manual',
        ]);
    }

    public function test_tracking_number_can_be_entered_before_shipping(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-NY'], '007-OVERSEA-TRACKING');

        $this->actingAs($user)->patch("/logistics/oversea/orders/{$order->id}/draft", [
            'tracking_number' => '9400111899223856928499',
        ])->assertRedirect();
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id, 'tracking_number' => '9400111899223856928499', 'shipment_status' => 'pending',
        ]);
    }

    public function test_rejection_requires_reason_clears_assignment_and_notifies_all_operations_users(): void
    {
        $dede = $this->userWith(['logistics.view', 'logistics.operate']);
        $opsA = $this->operationsUser('vita-a@example.test');
        $opsB = $this->operationsUser('vita-b@example.test');
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-NY'], '007-OVERSEA-REJECT');

        $this->actingAs($dede)->post("/logistics/oversea/orders/{$order->id}/reject", [])->assertSessionHasErrors('note');
        $this->actingAs($dede)->post("/logistics/oversea/orders/{$order->id}/reject", ['note' => '太贵，退回重新分仓'])->assertRedirect();

        $this->assertDatabaseHas('oversea_warehouse_reviews', [
            'order_id' => $order->id,
            'status' => 'rejected',
            'note' => '太贵，退回重新分仓',
        ]);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'warehouse_id' => null,
            'warehouse_selection_source' => null,
            'carrier' => null,
            'carrier_selection_source' => null,
        ]);

        foreach ([$opsA, $opsB] as $ops) {
            $notification = $ops->notifications()->first();
            $this->assertNotNull($notification);
            $this->assertSame('海外仓分配被驳回', $notification->data['title']);
            $this->assertSame('/logistics/wait-stock', $notification->data['action_url']);
            $this->assertStringContainsString('太贵，退回重新分仓', $notification->data['message']);
        }
    }

    public function test_pending_warehouse_orders_can_be_downloaded_as_standard_csv(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007-OVERSEA-EXPORT');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();

        $response = $this->actingAs($user)->get('/logistics/oversea/us-moreplus/export');
        $response->assertOk();
        $this->assertStringContainsString('attachment; filename=007-us-moreplus-orders-', (string) $response->headers->get('content-disposition'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('订单号', $content);
        $this->assertStringContainsString('007-OVERSEA-EXPORT', $content);
        $this->assertStringContainsString('USPS', $content);
    }

    public function test_tracking_return_preview_is_read_only_and_blocks_timestamp_without_timezone(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007-OVERSEA-RETURN-BLOCKED');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $csv = "订单号,Carrier,Tracking No.,发货时间(ISO8601含时区)\n007-OVERSEA-RETURN-BLOCKED,USPS,9400111899223856928499,2026-09-15 10:30:00\n";

        $this->actingAs($user)->post('/logistics/oversea/us-moreplus/tracking-import/preview', [
            'file' => UploadedFile::fake()->createWithContent('return.csv', $csv),
        ])->assertRedirect('/logistics/oversea/us-moreplus?view=pending');

        $this->actingAs($user)->get('/logistics/oversea/us-moreplus?view=pending')->assertInertia(fn (Assert $page) => $page
            ->where('trackingImportPreview.apply_count', 0)
            ->where('trackingImportPreview.blocked_count', 1)
            ->where('trackingImportPreview.rows.0.status', 'blocked'));
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'shipment_status' => 'pending', 'tracking_number' => null]);
    }

    public function test_tracking_return_apply_marks_shipped_with_exact_evidence_and_updates_review_progress(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007-OVERSEA-RETURN-APPLY');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $csv = "订单号,Carrier,Tracking No.,发货时间(ISO8601含时区)\n007-OVERSEA-RETURN-APPLY,USPS,9400111899223856928500,2026-09-15T10:30:00-07:00\n";

        $this->actingAs($user)->post('/logistics/oversea/us-moreplus/tracking-import/preview', [
            'file' => UploadedFile::fake()->createWithContent('return.csv', $csv),
        ])->assertRedirect('/logistics/oversea/us-moreplus?view=pending');
        $stored = session('logistics.oversea_tracking_return.us-moreplus');
        $this->assertIsArray($stored);
        $this->assertSame(1, $stored['apply_count']);
        $this->assertSame(0, $stored['blocked_count']);

        $this->actingAs($user)->post('/logistics/oversea/us-moreplus/tracking-import/apply', [
            'token' => $stored['token'],
        ])->assertRedirect('/logistics/oversea/us-moreplus?view=shipped');

        $shipment = $order->fresh('shipment')->shipment;
        $this->assertSame('shipped', $shipment->shipment_status);
        $this->assertSame('USPS', $shipment->carrier);
        $this->assertSame('9400111899223856928500', $shipment->tracking_number);
        $this->assertSame('2026-09-15T17:30:00+00:00', $shipment->shipped_at->utc()->toIso8601String());
        $this->assertDatabaseHas('oversea_warehouse_reviews', ['order_id' => $order->id, 'status' => 'approved', 'progress' => 'shipped']);
        $this->assertDatabaseHas('manual_syncs', ['order_id' => $order->id, 'website_sync_status' => 'pending']);
    }

    public function test_tracking_return_apply_immediately_pushes_woo_tracking_for_derived_package(): void
    {
        config([
            'services.woocommerce.url' => 'https://shop.test',
            'services.woocommerce.consumer_key' => 'ck_test',
            'services.woocommerce.consumer_secret' => 'cs_test',
            'services.woocommerce.connection_key' => 'shop-a',
        ]);
        $gateway = new TrackingReturnFakeWooGateway;
        $this->app->instance(WooCommerceTrackingGateway::class, $gateway);
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();

        $root = Order::query()->create([
            'internal_order_number' => 'ROOT-RETURN-WOO',
            'erp_order_id' => '007-OVERSEA-RETURN-WOO',
            'source_type' => 'woocommerce',
            'order_kind' => 'customer',
            'external_order_number' => 'WC-99001',
            'order_date' => '2026-09-15',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
        $root->externalReferences()->create([
            'provider' => 'woocommerce', 'connection_key' => 'shop-a', 'reference' => '99001',
        ]);
        $child = Order::query()->create([
            'fulfillment_parent_id' => $root->id,
            'fulfillment_suffix' => 'B',
            'internal_order_number' => 'CHILD-RETURN-WOO-B',
            'erp_order_id' => '007-OVERSEA-RETURN-WOOB',
            'source_type' => 'manual_other',
            'order_kind' => 'fulfillment_derived',
            'order_date' => '2026-09-15',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
        $child->items()->create([
            'woocommerce_line_item_id' => 990011,
            'product_summary' => 'Woo package B',
            'sku' => 'REVIEW-SKU',
            'quantity' => 1,
            'unit_price' => '10.00',
            'line_revenue' => '10.00',
        ]);
        $child->shipment()->create([
            'shipment_status' => 'pending',
            'warehouse_id' => $warehouses['US-LA']->id,
            'warehouse_selection_source' => 'manual',
        ]);
        $this->actingAs($user)->post("/logistics/oversea/orders/{$child->id}/approve")->assertRedirect();
        $csv = "订单号,Carrier,Tracking No.,发货时间(ISO8601含时区)\n007-OVERSEA-RETURN-WOOB,USPS,9400111899223856928511,2026-09-15T10:30:00-07:00\n";

        $this->actingAs($user)->post('/logistics/oversea/us-moreplus/tracking-import/preview', [
            'file' => UploadedFile::fake()->createWithContent('woo-return.csv', $csv),
        ])->assertRedirect('/logistics/oversea/us-moreplus?view=pending');
        $stored = session('logistics.oversea_tracking_return.us-moreplus');
        $this->actingAs($user)->post('/logistics/oversea/us-moreplus/tracking-import/apply', [
            'token' => $stored['token'],
        ])->assertRedirect('/logistics/oversea/us-moreplus?view=shipped');

        $this->assertSame(1, $gateway->trackingCalls);
        $this->assertSame(99001, $gateway->providerOrderId);
        $this->assertSame('9400111899223856928511', $gateway->trackingNumber);
        $this->assertSame([['id' => 990011, 'quantity' => 1]], $gateway->lineItems);
        $this->assertDatabaseHas('manual_syncs', ['order_id' => $child->id, 'website_sync_status' => 'completed']);
        $this->assertDatabaseHas('sync_outbox', ['operation' => 'woocommerce.tracking_outbound', 'status' => 'completed']);
    }

    public function test_view_only_user_sees_page_but_not_operate_flag(): void
    {
        $user = $this->userWith(['logistics.view']);
        $warehouses = $this->warehouses();
        $this->orderAt($warehouses['US-NY'], '007-OVERSEA-VIEW');
        $this->actingAs($user)->get('/logistics/oversea/us-ny')->assertInertia(fn (Assert $page) => $page->where('canOperate', false));
    }

    /** @return array<string,Warehouse> */
    private function warehouses(): array
    {
        $rows = [
            'US-NY' => ['us-ny', '美东纽约仓'],
            'US-LA' => ['us-la', '美西 MorePlus 仓'],
            'US-SM' => ['us-3sm', '美国3世迈仓'],
            'DE' => ['de', '德国天运仓'],
            'CA' => ['ca', '加拿大仓'],
        ];
        $result = [];
        foreach ($rows as $code => [$slug, $name]) {
            $result[$code] = Warehouse::query()->updateOrCreate(['code' => $code], [
                'slug' => $slug,
                'name' => $name,
                'type' => 'warehouse',
                'is_active' => true,
                'priority' => 10,
            ]);
        }
        Warehouse::query()->updateOrCreate(['code' => '007'], ['slug' => '007', 'name' => '007 本地仓库', 'type' => 'warehouse', 'is_active' => true, 'priority' => 99]);
        Warehouse::query()->updateOrCreate(['code' => 'FACTORY'], ['slug' => 'factory', 'name' => '中国工厂', 'type' => 'warehouse', 'is_active' => true, 'priority' => 98]);
        $product = Product::query()->firstOrCreate(['sku' => 'REVIEW-SKU'], ['name' => 'Review Product', 'product_type' => 'simple', 'is_active' => true]);
        foreach ($result as $warehouse) {
            InventorySyncBatch::query()->create(['warehouse_id' => $warehouse->id, 'source_type' => 'api', 'sync_mode' => 'full', 'status' => 'confirmed', 'confirmed_at' => now()]);
            WarehouseInventory::query()->updateOrCreate(['warehouse_id' => $warehouse->id, 'product_id' => $product->id], ['quantity_on_hand' => 100, 'quantity_reserved' => 0]);
        }
        return $result;
    }

    private function orderAt(Warehouse $warehouse, string $erpOrderId, string $country = 'US'): Order
    {
        $order = Order::query()->create([
            'internal_order_number' => 'INT-'.uniqid(),
            'erp_order_id' => $erpOrderId,
            'source_type' => 'manual_other',
            'order_kind' => 'customer',
            'external_order_number' => 'SOURCE-'.uniqid(),
            'order_date' => '2026-09-10',
            'order_status' => 'pending_shipment',
        ]);
        $order->address()->create([
            'recipient_name' => 'Review Recipient', 'phone' => '+1 555 0100', 'country' => $country,
            'province_state' => 'CA', 'city' => 'City', 'address_line_1' => '1 Main St', 'postal_code' => '90001',
        ]);
        $order->items()->create([
            'product_id' => Product::query()->where('sku', 'REVIEW-SKU')->value('id'),
            'product_summary' => 'Review Product', 'sku' => 'REVIEW-SKU', 'quantity' => 2,
            'unit_price' => '10.00', 'line_revenue' => '20.00',
        ]);
        $order->shipment()->create([
            'shipment_status' => 'pending', 'warehouse_id' => $warehouse->id,
            'warehouse_selection_source' => 'manual',
        ]);
        return $order->fresh(['address', 'items', 'shipment.warehouse']);
    }
    private function userWith(array $permissions): User
    {
        $role = Role::query()->create([
            'key' => 'oversea-test-'.uniqid(),
            'label' => 'Oversea Test',
        ]);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(
                ['key' => $permission],
                ['label' => $permission],
            );
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }

    private function operationsUser(string $email): User
    {
        $role = Role::query()->firstOrCreate([
            'key' => 'role_preset_operations',
        ], ['label' => '运营']);
        $user = User::factory()->create(['email' => $email, 'is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }
}

final class TrackingReturnFakeWooGateway implements WooCommerceTrackingGateway
{
    public int $trackingCalls = 0;
    public int $providerOrderId = 0;
    public string $trackingNumber = '';
    public array $lineItems = [];

    public function descriptor(): array
    {
        return ['configured' => true, 'url' => 'https://shop.test', 'connection_key' => 'shop-a'];
    }

    public function inspectTracking(int $providerOrderId): array
    {
        return ['tracking_number' => $this->trackingNumber, 'provider' => 'usps', 'ship_date' => '2026-09-15', 'has_tracking' => $this->trackingNumber !== ''];
    }

    public function syncTracking(int $providerOrderId, string $providerSlug, string $trackingNumber, string $shipDate, array $lineItems = []): array
    {
        $this->trackingCalls++;
        $this->providerOrderId = $providerOrderId;
        $this->trackingNumber = $trackingNumber;
        $this->lineItems = $lineItems;

        return ['written' => true, 'verified' => true];
    }

    public function syncOrderStatus(int $providerOrderId, string $status): array
    {
        return ['written' => true, 'verified' => true, 'status' => $status];
    }
}
