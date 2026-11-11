<?php

namespace Tests\Feature\Logistics;

use App\Models\Role;
use App\Models\User;
use App\Modules\Logistics\Application\FulfillmentAllocationSubmissionService;
use App\Modules\Logistics\Application\LogisticsStatusSummary;
use App\Modules\Logistics\Application\ShipmentService;
use App\Modules\Orders\Application\OrderService;
use App\Modules\Orders\Application\OrderWorkflowStatusProjection;
use App\Modules\Logistics\Infrastructure\Models\FulfillmentAllocation;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class FulfillmentAllocationV1Test extends TestCase
{
    use RefreshDatabase;

    public function test_wait_stock_page_is_vita_assisted_allocation_surface_and_excludes_007(): void
    {
        [$user, $order] = $this->scenario(1);
        self::assertTrue($user->fresh('roles.permissions')->can('logistics.view'));

        $this->actingAs($user)->get('/logistics/wait-stock')->assertOk()->assertInertia(
            fn (Assert $page) => $page
                ->component('Logistics/FulfillmentAllocation')
                ->where('orders.data.0.id', $order->id)
                ->where('globalGridPilotAvailable', true)
                ->where('globalGridPilotEnabled', true)
                ->where('orders.data.0.allocation_decision.status', 'manual_review')
                ->where('orders.data.0.allocation_decision.policy.system_is_advisory', true)
                ->where('orders.data.0.allocation_decision.policy.human_confirmation_required', true)
                ->where('warehouses', function ($rows): bool {
                    $codes = collect($rows)->pluck('code')->all();

                    return ! in_array('007', $codes, true)
                        && in_array('FACTORY', $codes, true)
                        && collect($codes)->diff(['FACTORY', 'US-NY', 'US-LA', 'US-SM', 'US-AMZ', 'DE', 'CA'])->isEmpty();
                })
        );
    }

    public function test_wait_stock_legacy_is_an_explicit_opt_out_and_default_restores_global_grid(): void
    {
        [$user, $order] = $this->scenario(1);
        $this->actingAs($user)->get('/logistics/wait-stock?global_grid=0')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Logistics/FulfillmentAllocation')
                ->where('globalGridPilotEnabled', false)
                ->where('orders.data.0.id', $order->id));

        $this->actingAs($user)->get('/logistics/wait-stock?global_grid=1')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Logistics/FulfillmentAllocation')
                ->where('globalGridPilotEnabled', true));
    }

    public function test_wait_stock_archive_only_operator_uses_existing_audited_batch_and_suppresses_archived_order(): void
    {
        [, $order] = $this->scenario(1);
        $viewer = $this->userWith(['logistics.view', 'orders.view']);
        $archiver = $this->userWith(['logistics.view', 'orders.view', 'orders.archive']);

        $this->actingAs($viewer)->get('/logistics/wait-stock')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Logistics/FulfillmentAllocation')
                ->where('canArchive', false)
                ->where('canOperate', false));
        $this->actingAs($archiver)->get('/logistics/wait-stock')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Logistics/FulfillmentAllocation')
                ->where('canArchive', true)
                ->where('canOperate', false));

        $this->actingAs($viewer)->postJson('/orders/archive/batch', [
            'order_ids' => [$order->id],
        ])->assertForbidden();
        $this->actingAs($archiver)->postJson('/orders/archive/batch', [
            'order_ids' => [$order->id],
        ])->assertOk()->assertJsonPath('success_count', 1)
            ->assertJsonPath('failed_count', 0);
        $this->assertNotNull($order->fresh()->operational_archived_at);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id, 'deleted_at' => null, 'operational_archived_by' => $archiver->id,
        ]);

        $this->actingAs($archiver)->get('/logistics/wait-stock')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Logistics/FulfillmentAllocation')
                ->where('orders.total', 0));
        $this->actingAs($archiver)->postJson('/orders/archive/batch', [
            'order_ids' => [$order->id],
        ])->assertOk()->assertJsonPath('success_count', 0)
            ->assertJsonPath('failed_count', 1);
    }

    public function test_batch_allocation_requires_operate_permission_and_explicit_confirmation(): void
    {
        [$owner, $order] = $this->scenario(1);
        $viewer = $this->userWith(['logistics.view']);
        $this->actingAs($viewer)->postJson('/logistics/wait-stock/batch-allocation', [
            'order_ids' => [$order->id],
            'action' => 'warehouse',
            'warehouse_code' => 'US-LA',
            'confirmed' => true,
        ])->assertForbidden();

        $this->actingAs($owner)->postJson('/logistics/wait-stock/batch-allocation', [
            'order_ids' => [$order->id],
            'action' => 'warehouse',
            'warehouse_code' => 'US-LA',
        ])->assertUnprocessable();

        $this->actingAs($owner)->postJson('/logistics/wait-stock/batch-allocation', [
            'order_ids' => [$order->id, $order->id],
            'action' => 'warehouse',
            'warehouse_code' => 'US-LA',
            'confirmed' => true,
        ])->assertUnprocessable();
        $this->assertDatabaseMissing('fulfillment_allocations', ['order_id' => $order->id]);
    }

    public function test_batch_manual_isolates_gift_failures_and_never_repeats_a_submitted_order(): void
    {
        [$owner, $eligible] = $this->scenario(1);
        [, $gift] = $this->scenario(1);
        $gift->items()->firstOrFail()->forceFill(['sku' => 'gift'])->save();

        $warehouse = Warehouse::query()->where('code', 'US-LA')->firstOrFail();
        $productId = (int) $eligible->items()->firstOrFail()->product_id;
        WarehouseInventory::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $productId,
            'quantity_on_hand' => 20,
            'quantity_reserved' => 0,
            'inbound_at' => now()->subDays(3),
            'location' => 'BATCH-UAT-001',
        ]);

        $payload = [
            'order_ids' => [$eligible->id, $gift->id],
            'action' => 'warehouse',
            'warehouse_code' => 'US-LA',
            'confirmed' => true,
        ];
        $response = $this->actingAs($owner)->postJson('/logistics/wait-stock/batch-allocation', $payload)
            ->assertOk()->assertJsonPath('results.1.ok', false);
        $this->assertDatabaseMissing('fulfillment_allocations', ['order_id' => $gift->id]);

        // Confirm one real warehouse allocation and one isolated gift rejection.
        $response->assertJsonPath('succeeded', 1)->assertJsonPath('failed', 1)
            ->assertJsonPath('results.0.ok', true);
        $this->assertDatabaseHas('fulfillment_allocations', [
            'order_id' => $eligible->id, 'status' => 'submitted',
        ]);
        $retry = $this->actingAs($owner)->postJson('/logistics/wait-stock/batch-allocation', $payload)
            ->assertOk()->assertJsonPath('succeeded', 0)
            ->assertJsonPath('results.0.ok', false)
            ->assertJsonPath('results.1.ok', false);
        self::assertSame(2, count((array) $response->json('results')));
    }

    public function test_batch_recommend_requires_matching_per_order_plan_key(): void
    {
        [$owner, $order] = $this->scenario(1);
        $this->actingAs($owner)->postJson('/logistics/wait-stock/batch-allocation', [
            'order_ids' => [$order->id],
            'action' => 'recommend',
            'confirmed' => true,
            'plan_keys' => [],
        ])->assertOk()->assertJsonPath('succeeded', 0)
            ->assertJsonPath('results.0.ok', false);
        $this->assertDatabaseMissing('fulfillment_allocations', ['order_id' => $order->id]);
    }

    public function test_manual_split_materializes_a_b_derived_orders_and_downstream_logistics_only_sees_children(): void
    {
        [$user, $order] = $this->scenario(2);
        $items = $order->items()->orderBy('id')->get();

        $this->actingAs($user)->postJson('/logistics/orders/'.$order->id.'/fulfillment-allocation/manual', [
            'assignments' => [
                ['order_item_id' => $items[0]->id, 'source_code' => 'US-LA'],
                ['order_item_id' => $items[1]->id, 'source_code' => 'FACTORY'],
            ],
            'decision_note' => '人工确认拆分更适合本单',
            'stock_exception_acknowledged' => true,
        ])->assertOk()->assertJsonPath('decision_source', 'manual');

        $allocation = FulfillmentAllocation::query()->where('order_id', $order->id)->firstOrFail();
        self::assertSame('submitted', $allocation->status);
        self::assertSame('manual', $allocation->decision_source);
        self::assertTrue((bool) ($allocation->plan_snapshot['is_split'] ?? false));
        self::assertSame(2, $allocation->plan_snapshot['shipment_count']);
        self::assertSame(['FACTORY', 'US-LA'], collect($allocation->plan_snapshot['segments'])->pluck('source_code')->sort()->values()->all());
        $this->assertDatabaseMissing('shipments', ['order_id' => $order->id]);

        $children = $order->fresh()->fulfillmentChildren()->with(['items', 'shipment.warehouse'])->orderBy('fulfillment_suffix')->get();
        self::assertCount(2, $children);
        self::assertSame([$order->erp_order_id.'A', $order->erp_order_id.'B'], $children->pluck('erp_order_id')->all());
        self::assertSame(['A', 'B'], $children->pluck('fulfillment_suffix')->all());
        self::assertSame([$order->order_date->toDateString(), $order->order_date->toDateString()], $children->map(fn ($child) => $child->order_date->toDateString())->all());
        self::assertSame([$order->owner_id, $order->owner_id], $children->pluck('owner_id')->all());
        self::assertSame(['fulfillment_derived', 'fulfillment_derived'], $children->map(fn ($child) => $child->order_kind?->value)->all());
        self::assertSame(['US-LA', 'FACTORY'], $children->map(fn ($child) => $child->shipment?->warehouse?->code)->all());
        self::assertSame(['USPS', null], $children->map(fn ($child) => $child->shipment?->carrier)->all());
        self::assertSame(['automatic', null], $children->map(fn ($child) => $child->shipment?->carrier_selection_source)->all());
        self::assertNull($children[1]->shipment?->service);
        self::assertNull(collect($allocation->plan_snapshot['derived_orders'])->last()['carrier'] ?? null);
        self::assertSame([$items[0]->sku, $items[1]->sku], $children->map(fn ($child) => $child->items->first()?->sku)->all());
        self::assertSame([$order->erp_order_id.'A', $order->erp_order_id.'B'], collect($allocation->fresh()->plan_snapshot['derived_orders'])->pluck('erp_order_id')->all());

        $this->actingAs($user)->get('/logistics/pending')->assertRedirect('/logistics/wait-stock');
    }

    public function test_split_children_inherit_woo_line_item_identity_from_parent_items(): void
    {
        [$user, $order] = $this->scenario(2);
        $items = $order->items()->orderBy('id')->get();
        $items[0]->forceFill(['woocommerce_line_item_id' => 91001])->save();
        $items[1]->forceFill(['woocommerce_line_item_id' => 91002])->save();

        $this->actingAs($user)->postJson('/logistics/orders/'.$order->id.'/fulfillment-allocation/manual', [
            'assignments' => [
                ['order_item_id' => $items[0]->id, 'source_code' => 'US-LA'],
                ['order_item_id' => $items[1]->id, 'source_code' => 'FACTORY'],
            ],
            'decision_note' => '验证 Woo line item identity 继承',
            'stock_exception_acknowledged' => true,
        ])->assertOk();

        $children = $order->fresh()->fulfillmentChildren()->with('items')->orderBy('fulfillment_suffix')->get();
        self::assertCount(2, $children);
        self::assertSame([91001, 91002], $children->map(fn ($child) => $child->items->first()?->woocommerce_line_item_id)->all());
    }

    public function test_manual_multi_source_materializes_a_b_c_from_three_overseas_sources(): void
    {
        [$user, $order] = $this->scenario(3);
        $items = $order->items()->orderBy('id')->get();

        $allocation = app(FulfillmentAllocationSubmissionService::class)->submitManual($order, [
            ['order_item_id' => $items[0]->id, 'source_code' => 'US-LA'],
            ['order_item_id' => $items[1]->id, 'source_code' => 'US-NY'],
            ['order_item_id' => $items[2]->id, 'source_code' => 'US-SM'],
        ], 'ABC multi-warehouse fulfillment', $user, true);

        self::assertSame(3, $allocation->plan_snapshot['shipment_count']);
        self::assertTrue((bool) $allocation->plan_snapshot['is_split']);
        self::assertSame(['US-LA', 'US-NY', 'US-SM'], collect($allocation->plan_snapshot['segments'])->pluck('source_code')->all());

        $children = $order->fresh()->originalFulfillmentChildren()->with(['items', 'shipment.warehouse'])->orderBy('fulfillment_suffix')->get();
        self::assertCount(3, $children);
        self::assertSame(['A', 'B', 'C'], $children->pluck('fulfillment_suffix')->all());
        self::assertSame([
            $order->erp_order_id.'A',
            $order->erp_order_id.'B',
            $order->erp_order_id.'C',
        ], $children->pluck('erp_order_id')->all());
        self::assertSame(['US-LA', 'US-NY', 'US-SM'], $children->map(fn ($child) => $child->shipment?->warehouse?->code)->all());
        self::assertSame(['USPS', 'USPS', 'USPS'], $children->map(fn ($child) => $child->shipment?->carrier)->all());
        self::assertSame(['A', 'B', 'C'], collect($allocation->fresh()->plan_snapshot['derived_orders'])->pluck('suffix')->all());
    }

    public function test_split_source_shows_allocated_and_children_cannot_ship_before_warehouse_review(): void
    {
        [$user, $root] = $this->scenario(2);
        $items = $root->items()->orderBy('id')->get();

        $this->actingAs($user)->postJson('/logistics/orders/'.$root->id.'/fulfillment-allocation/manual', [
            'assignments' => [
                ['order_item_id' => $items[0]->id, 'source_code' => 'US-SM'],
                ['order_item_id' => $items[1]->id, 'source_code' => 'FACTORY'],
            ],
            'stock_exception_acknowledged' => true,
        ])->assertOk();

        $freshRoot = $root->fresh(['fulfillmentAllocation']);
        self::assertSame('pending_shipment', $freshRoot->order_status->value);
        self::assertSame('allocated', app(OrderWorkflowStatusProjection::class)->for($freshRoot)['code']);
        self::assertFalse(app(LogisticsStatusSummary::class)->pendingAllocationQuery()->whereKey($root->id)->exists());
        self::assertNotContains($root->id, app(OrderService::class)->list(['view' => 'pending'])
            ->getCollection()->pluck('id')->all());

        $children = $root->originalFulfillmentChildren()->with('shipment.warehouse')->orderBy('fulfillment_suffix')->get();
        self::assertSame(['US-SM', 'FACTORY'], $children->map(fn ($child) => $child->shipment->warehouse->code)->all());
        self::assertSame(['pending', 'pending'], $children->map(fn ($child) => $child->shipment->shipment_status)->all());

        foreach ($children as $child) {
            try {
                app(ShipmentService::class)->markShipped($child, [
                    'warehouse_id' => (int) $child->shipment->warehouse_id,
                    'carrier' => $child->shipment->carrier,
                    'service' => $child->shipment->service,
                    'shipped_at' => now()->toIso8601String(),
                ], $user);
                self::fail('A split segment bypassed warehouse review.');
            } catch (HttpException $exception) {
                self::assertSame(409, $exception->getStatusCode());
                self::assertMatchesRegularExpression('/审核|商务确认/u', $exception->getMessage());
            }
            self::assertSame('pending_shipment', $child->fresh()->order_status->value);
            self::assertSame('pending', $child->fresh()->shipment->shipment_status);
            self::assertNull($child->fresh()->shipment->shipped_at);
        }
        $this->assertDatabaseMissing('audit_records', ['action' => 'shipment.marked_shipped']);
    }

    public function test_manual_v1_requires_every_order_item_and_forbids_duplicate_item_split(): void
    {
        [$user, $order] = $this->scenario(2);
        $items = $order->items()->orderBy('id')->get();

        $service = app(FulfillmentAllocationSubmissionService::class);
        try {
            $service->submitManual($order, [
                ['order_item_id' => $items[0]->id, 'source_code' => 'FACTORY'],
                ['order_item_id' => $items[0]->id, 'source_code' => 'US-LA'],
            ], null, $user);
            self::fail('Expected duplicate order item allocation to be rejected.');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->getStatusCode());
            self::assertSame('同一个订单商品不能拆数量到多个仓库', $exception->getMessage());
        }

        try {
            $service->submitManual($order, [
                ['order_item_id' => $items[0]->id, 'source_code' => 'FACTORY'],
            ], null, $user);
            self::fail('Expected incomplete order item allocation to be rejected.');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->getStatusCode());
            self::assertSame('必须为订单中的每个 SKU 完成分仓', $exception->getMessage());
        }
    }

    public function test_view_only_user_cannot_submit_allocation(): void
    {
        [, $order] = $this->scenario(1);
        $viewer = $this->userWith(['logistics.view']);
        $item = $order->items()->firstOrFail();

        $this->actingAs($viewer)->postJson('/logistics/orders/'.$order->id.'/fulfillment-allocation/manual', [
            'assignments' => [['order_item_id' => $item->id, 'source_code' => 'FACTORY']],
        ])->assertForbidden();
    }

    /** @return array{User,Order} */
    private function scenario(int $itemCount): array
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $order = Order::query()->create([
            'internal_order_number' => 'ALLOC-'.uniqid(),
            'erp_order_id' => '007-0911-'.random_int(10000, 99999),
            'source_type' => 'manual',
            'order_kind' => 'customer',
            'external_order_number' => 'EXT-'.uniqid(),
            'order_date' => '2026-11-04',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
        $order->address()->create([
            'recipient_name' => 'Vita Allocation UAT',
            'phone' => '10000000000',
            'country' => 'US',
            'province_state' => 'CA',
            'city' => 'Los Angeles',
            'address_line_1' => '123 Test Ave',
            'address_line_2' => null,
            'postal_code' => '90001',
        ]);
        for ($i = 0; $i < $itemCount; $i++) {
            $product = Product::query()->create([
                'sku' => 'ALLOC-SKU-'.$i.'-'.uniqid(),
                'name' => 'Allocation Product '.$i,
                'product_type' => 'simple',
                'source_type' => 'manual',
                'is_active' => true,
            ]);
            $order->items()->create([
                'product_id' => $product->id,
                'product_summary' => $product->name,
                'sku' => $product->sku,
                'quantity' => 1,
                'unit_price' => '19.99',
                'line_revenue' => '19.99',
            ]);
        }

        return [$user, $order->fresh(['items', 'address'])];
    }

    private function userWith(array $permissions): User
    {
        $role = Role::query()->create(['key' => 'allocation-'.uniqid(), 'label' => 'Allocation Test']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
