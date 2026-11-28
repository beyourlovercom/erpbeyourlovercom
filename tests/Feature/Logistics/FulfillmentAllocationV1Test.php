<?php

namespace Tests\Feature\Logistics;

use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Logistics\Application\FulfillmentAllocationSubmissionService;
use App\Modules\Logistics\Application\LogisticsStatusSummary;
use App\Modules\Logistics\Application\MorePlusPhysicalStockReader;
use App\Modules\Logistics\Application\ShipmentService;
use App\Modules\Logistics\Infrastructure\Models\FulfillmentAllocation;
use App\Modules\Orders\Application\OrderService;
use App\Modules\Orders\Application\OrderWorkflowStatusProjection;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductWarehouseSku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class FulfillmentAllocationV1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('inertia.testing.ensure_pages_exist', false);
    }

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
                        && collect($codes)->diff(['FACTORY', 'US-NY', 'US-LA', 'US-SM', 'US-AMZ', 'DE', 'CA', 'TINGON'])->isEmpty();
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

    public function test_legacy_unassigned_route_with_rejected_allocation_can_submit_one_us_sm_shipment(): void
    {
        [$user, $order] = $this->scenario(2);
        $items = $order->items()->orderBy('id')->get();
        $usSm = Warehouse::query()->where('code', 'US-SM')->firstOrFail();
        $order->forceFill([
            'routing_warehouse_code' => 'UNASSIGNED',
            'routing_revision' => 1,
            'workflow_status' => 'pending_allocation',
        ])->save();
        $order->shipment()->create([
            'warehouse_id' => null,
            'shipment_status' => 'pending',
        ]);
        FulfillmentAllocation::query()->create([
            'order_id' => $order->id,
            'status' => 'rejected',
            'decision_source' => 'manual',
        ]);

        $this->actingAs($user)->postJson('/logistics/orders/'.$order->id.'/fulfillment-allocation/manual', [
            'assignments' => [
                ['order_item_id' => $items[0]->id, 'source_code' => 'US-SM'],
                ['order_item_id' => $items[1]->id, 'source_code' => 'US-SM'],
            ],
            'decision_note' => 'Legacy routing fix UAT: preserve source SKU and select actual warehouse',
            'stock_exception_acknowledged' => true,
        ])->assertOk()->assertJsonPath('status', 'submitted');

        $fresh = $order->fresh(['shipment', 'items']);
        self::assertSame('US-SM', $fresh->routing_warehouse_code);
        self::assertSame(2, $fresh->routing_revision);
        self::assertSame((int) $usSm->id, (int) $fresh->shipment->warehouse_id);
        self::assertSame('allocation', $fresh->shipment->warehouse_selection_source);
        self::assertSame('pending_review', $fresh->workflow_status);
        self::assertSame(1, $fresh->workflow_revision);
        self::assertCount(0, $fresh->originalFulfillmentChildren);
        self::assertSame($items->pluck('sku')->all(), $fresh->items->sortBy('id')->pluck('source_sku')->values()->all());
        $this->assertDatabaseHas('order_warehouse_route_histories', [
            'order_id' => $order->id,
            'actor_id' => $user->id,
            'from_code' => 'UNASSIGNED',
            'to_code' => 'US-SM',
            'revision' => 2,
        ]);
        $this->assertDatabaseHas('fulfillment_allocations', [
            'order_id' => $order->id,
            'status' => 'submitted',
        ]);
    }

    public function test_factory_allocation_waits_for_liang_review_before_ready_queue(): void
    {
        config()->set('warehouse_final_sku.require_verified_mapping', true);
        [$user, $order] = $this->scenario(1);
        $item = $order->items()->firstOrFail();
        app(FulfillmentAllocationSubmissionService::class)->submitManual($order, [
            ['order_item_id' => $item->id, 'source_code' => 'FACTORY'],
        ], null, $user, true);

        $fresh = $order->fresh();
        self::assertSame('pending_shipment', $fresh->order_status->value);
        self::assertSame('pending_review', $fresh->workflow_status);
        $progress = app(\App\Modules\Orders\Application\OrderProgressQuery::class);
        self::assertSame(1, $progress->domesticReview(Order::query())->whereKey($order->id)->count());
        self::assertSame(0, $progress->domesticReady(Order::query())->whereKey($order->id)->count());
        $this->assertDatabaseMissing('order_operational_markers', ['order_id' => $order->id, 'marker' => 'domestic_reviewed']);

        app(\App\Modules\Logistics\Application\Domestic\DomesticReviewDecisionService::class)
            ->decide($fresh, 'approve', null, $user);
        self::assertSame('sending', $order->fresh()->workflow_status);
        self::assertSame(0, $progress->domesticReview(Order::query())->whereKey($order->id)->count());
        self::assertSame(1, $progress->domesticReady(Order::query())->whereKey($order->id)->count());
    }

    public function test_us_ny_split_allocation_reserves_only_actual_physical_sku(): void
    {
        config()->set('xlwms.us_ny_inventory_physical_apply_enabled', true);
        [$user, $order] = $this->scenario(2);
        $items = $order->items()->orderBy('id')->get();
        $ny = Warehouse::query()->where('code', 'US-NY')->firstOrFail();
        ProductWarehouseSku::query()->create(['product_id' => $items[0]->product_id,
            'warehouse_id' => $ny->id, 'sku' => 'NY-ACTUAL', 'is_active' => true, 'verified_at' => now()]);
        $stock = \App\Modules\Inventory\Infrastructure\Models\WarehouseInventory::query()->create([
            'warehouse_id' => $ny->id, 'product_id' => $items[0]->product_id,
            'quantity_on_hand' => 10, 'quantity_reserved' => 0,
            'provider_available_quantity' => 10, 'provider_stock_synced_at' => now()]);
        app(FulfillmentAllocationSubmissionService::class)->submitManual($order, [
            ['order_item_id' => $items[0]->id, 'source_code' => 'US-NY'],
            ['order_item_id' => $items[1]->id, 'source_code' => 'FACTORY'],
        ], null, $user, true);
        $nyChild = $order->fresh()->originalFulfillmentChildren()->with('shipment.warehouse','items')->get()
            ->first(fn ($child) => $child->shipment->warehouse->code === 'US-NY');
        self::assertNotNull($nyChild);
        self::assertSame('NY-ACTUAL', $nyChild->items->first()->final_sku);
        self::assertSame(1, $stock->fresh()->quantity_reserved);
        $this->assertDatabaseHas('inventory_reservations', ['order_id' => $nyChild->id, 'warehouse_id' => $ny->id, 'status' => 'reserved']);
        $this->assertDatabaseMissing('inventory_reservations', ['order_id' => $order->id]);
        self::assertSame(9, app(\App\Modules\Inventory\Application\InventoryAvailabilityProjection::class)->forRows(collect([$stock->fresh()]))[$stock->id]);
    }

    public function test_overseas_allocation_waits_for_dede_review_before_sending(): void
    {
        [$user, $order] = $this->scenario(1);
        $item = $order->items()->firstOrFail();
        app(FulfillmentAllocationSubmissionService::class)->submitManual($order, [
            ['order_item_id' => $item->id, 'source_code' => 'US-SM'],
        ], null, $user, true);

        $fresh = $order->fresh();
        self::assertSame('pending_review', $fresh->workflow_status);
        $progress = app(\App\Modules\Orders\Application\OrderProgressQuery::class);
        self::assertSame(1, $progress->applyPhase(Order::query(), 'pending_review')->whereKey($order->id)->count());
        self::assertSame(0, $progress->readyForShipment(Order::query())->whereKey($order->id)->count());

        app(\App\Modules\Logistics\Application\OverseaWarehouseReviewService::class)->approve($fresh, $user);
        self::assertSame('sending', $order->fresh()->workflow_status);
        self::assertSame(0, $progress->applyPhase(Order::query(), 'pending_review')->whereKey($order->id)->count());
        self::assertSame(1, $progress->readyForShipment(Order::query())->whereKey($order->id)->count());
    }

    public function test_explicit_manual_sending_override_is_still_available_after_allocation(): void
    {
        [$user, $order] = $this->scenario(1);
        app(FulfillmentAllocationSubmissionService::class)->submitManual($order, [
            ['order_item_id' => $order->items()->firstOrFail()->id, 'source_code' => 'FACTORY'],
        ], null, $user, true);
        self::assertSame('pending_review', $order->fresh()->workflow_status);
        app(\App\Modules\Orders\Application\OrderWorkflowStatusService::class)
            ->set($order->fresh(), 'sending', (int) $order->fresh()->workflow_revision, $user);

        self::assertSame('sending', $order->fresh()->workflow_status);
        self::assertSame(1, app(\App\Modules\Orders\Application\OrderProgressQuery::class)
            ->domesticReady(Order::query())->whereKey($order->id)->count());
        $this->assertDatabaseMissing('order_operational_markers', [
            'order_id' => $order->id, 'marker' => 'domestic_reviewed',
        ]);
    }

    public function test_non_review_warehouses_are_not_reclassified_by_allocation(): void
    {
        foreach (['TINGON', 'US-AMZ'] as $warehouseCode) {
            [$user, $order] = $this->scenario(1);
            app(FulfillmentAllocationSubmissionService::class)->submitManual($order, [
                ['order_item_id' => $order->items()->firstOrFail()->id, 'source_code' => $warehouseCode],
            ], null, $user, true);

            self::assertSame($warehouseCode, $order->fresh('shipment.warehouse')->shipment?->warehouse?->code);
            self::assertNull($order->fresh()->workflow_status);
            self::assertSame(0, (int) $order->fresh()->workflow_revision);
        }
    }

    public function test_direct_shipment_draft_still_forbids_real_warehouse_mismatch(): void
    {
        [$user, $order] = $this->scenario(1);
        $usSm = Warehouse::query()->where('code', 'US-SM')->firstOrFail();
        $order->forceFill([
            'routing_warehouse_code' => 'US-NY',
            'routing_revision' => 3,
        ])->save();

        try {
            app(ShipmentService::class)->saveDraft($order, [
                'warehouse_id' => $usSm->id,
                'warehouse_selection_source' => 'manual',
            ], $user);
            self::fail('A direct cross-warehouse shipment draft must remain blocked');
        } catch (HttpException $exception) {
            self::assertSame(409, $exception->getStatusCode());
        }

        $this->assertDatabaseMissing('shipments', ['order_id' => $order->id]);
        self::assertSame('US-NY', $order->fresh()->routing_warehouse_code);
        self::assertSame(3, $order->fresh()->routing_revision);
        $this->assertDatabaseMissing('order_warehouse_route_histories', ['order_id' => $order->id]);
    }

    public function test_manual_split_materializes_a_b_derived_orders_and_downstream_logistics_only_sees_children(): void
    {
        [$user, $order] = $this->scenario(2);
        $items = $order->items()->orderBy('id')->get();
        app(\App\Modules\Orders\Application\OrderWorkflowStatusService::class)
            ->set($order, 'pending_allocation', (int) $order->workflow_revision, $user);

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
        self::assertNull($order->fresh()->workflow_status);
        self::assertSame(2, $order->fresh()->workflow_revision);
        $this->assertDatabaseMissing('shipments', ['order_id' => $order->id]);

        $children = $order->fresh()->fulfillmentChildren()->with(['items', 'shipment.warehouse'])->orderBy('fulfillment_suffix')->get();
        self::assertCount(2, $children);
        self::assertSame([$order->erp_order_id.'A', $order->erp_order_id.'B'], $children->pluck('erp_order_id')->all());
        self::assertSame(['A', 'B'], $children->pluck('fulfillment_suffix')->all());
        self::assertSame([$order->order_date->toDateString(), $order->order_date->toDateString()], $children->map(fn ($child) => $child->order_date->toDateString())->all());
        self::assertSame([$order->owner_id, $order->owner_id], $children->pluck('owner_id')->all());
        self::assertSame(['fulfillment_derived', 'fulfillment_derived'], $children->map(fn ($child) => $child->order_kind?->value)->all());
        self::assertSame(['US-LA', 'FACTORY'], $children->map(fn ($child) => $child->shipment?->warehouse?->code)->all());
        self::assertSame(['US-LA', 'FACTORY'], $children->pluck('routing_warehouse_code')->all());
        self::assertSame(['pending_review', 'pending_review'], $children->pluck('workflow_status')->all());
        self::assertSame([1, 1], $children->pluck('workflow_revision')->all());
        self::assertSame([1, 1], $children->pluck('routing_revision')->all());
        self::assertSame(['USPS', null], $children->map(fn ($child) => $child->shipment?->carrier)->all());
        self::assertSame(['automatic', null], $children->map(fn ($child) => $child->shipment?->carrier_selection_source)->all());
        self::assertNull($children[1]->shipment?->service);
        self::assertNull(collect($allocation->plan_snapshot['derived_orders'])->last()['carrier'] ?? null);
        self::assertSame([$items[0]->sku, $items[1]->sku], $children->map(fn ($child) => $child->items->first()?->sku)->all());
        self::assertSame([$order->erp_order_id.'A', $order->erp_order_id.'B'], collect($allocation->fresh()->plan_snapshot['derived_orders'])->pluck('erp_order_id')->all());

        $this->actingAs($user)->get('/logistics/pending')->assertRedirect('/logistics/wait-stock');
    }

    public function test_verified_mapping_materializes_distinct_final_skus_for_each_split_shipping_warehouse(): void
    {
        [$user, $order] = $this->scenario(2);
        $items = $order->items()->orderBy('id')->get();
        config()->set('warehouse_final_sku.require_verified_mapping', true);
        foreach ([0 => ['US-LA', 'MP-PHYSICAL'], 1 => ['FACTORY', 'CN-PHYSICAL']] as $index => [$code, $sku]) {
            $warehouse = Warehouse::query()->where('code', $code)->firstOrFail();
            ProductWarehouseSku::query()->create([
                'product_id' => $items[$index]->product_id,
                'warehouse_id' => $warehouse->id,
                'sku' => $sku, 'is_active' => true, 'verified_at' => now(),
                'verification_note' => 'Physical warehouse identity verified for split order',
            ]);
        }

        app(FulfillmentAllocationSubmissionService::class)->submitManual($order, [
            ['order_item_id' => $items[0]->id, 'source_code' => 'US-LA'],
            ['order_item_id' => $items[1]->id, 'source_code' => 'FACTORY'],
        ], 'Independent per-line fulfillment SKU verification', $user, true);

        $children = $order->fresh()->originalFulfillmentChildren()
            ->with(['items', 'shipment.warehouse'])->orderBy('fulfillment_suffix')->get();
        self::assertSame(['US-LA', 'FACTORY'], $children->map(fn ($c) => $c->shipment->warehouse->code)->all());
        self::assertSame(['MP-PHYSICAL', 'CN-PHYSICAL'], $children->map(fn ($c) => $c->items->first()->final_sku)->all());
        self::assertSame($items->pluck('sku')->all(), $children->map(fn ($c) => $c->items->first()->source_sku)->all());
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
        self::assertSame(['pending_review', 'pending_review', 'pending_review'], $children->pluck('workflow_status')->all());
        self::assertSame(['USPS', 'USPS', 'USPS'], $children->map(fn ($child) => $child->shipment?->carrier)->all());
        self::assertSame(['A', 'B', 'C'], collect($allocation->fresh()->plan_snapshot['derived_orders'])->pluck('suffix')->all());
    }

    public function test_split_source_shows_allocated_and_only_overseas_child_requires_warehouse_review(): void
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
            $attributes = [
                'warehouse_id' => (int) $child->shipment->warehouse_id,
                'carrier' => $child->shipment->carrier,
                'service' => $child->shipment->service,
                'tracking_number' => 'UAT-SPLIT-'.$child->fulfillment_suffix,
                'shipped_at' => now()->toIso8601String(),
            ];
            if ($child->shipment->warehouse->code === 'FACTORY') {
                app(ShipmentService::class)->markShipped($child, $attributes, $user);
                self::assertSame('shipped', $child->fresh()->order_status->value);
                self::assertSame('shipped', $child->fresh()->shipment->shipment_status);
                self::assertSame('UAT-SPLIT-'.$child->fulfillment_suffix, $child->fresh()->shipment->tracking_number);
                self::assertFalse($child->fresh()->operationalMarkers()->where('marker', 'domestic_reviewed')->exists());
                continue;
            }
            try {
                app(ShipmentService::class)->markShipped($child, $attributes, $user);
                self::fail('An overseas split segment bypassed warehouse review.');
            } catch (HttpException $exception) {
                self::assertSame(409, $exception->getStatusCode());
                self::assertMatchesRegularExpression('/审核|商务确认/u', $exception->getMessage());
            }
            self::assertSame('pending_shipment', $child->fresh()->order_status->value);
            self::assertSame('pending', $child->fresh()->shipment->shipment_status);
            self::assertNull($child->fresh()->shipment->shipped_at);
        }
        $this->assertDatabaseHas('audit_records', ['action' => 'shipment.marked_shipped']);
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

    public function test_manual_sku_preflight_allows_unverified_moreplus_sku_without_mutating_order(): void
    {
        [$user, $order] = $this->scenario(1);
        $item = $order->items()->firstOrFail();
        config()->set('shipout.production_read_enabled', true);
        config()->set('shipout.production_warehouse_id', '1265864414750314497');
        $this->mock(MorePlusPhysicalStockReader::class)->shouldReceive('snapshot')->once()->andReturn([
            'warehouse_id' => '1265864414750314497',
            'stock_rows' => [],
        ]);

        $this->actingAs($user)->postJson('/logistics/orders/'.$order->id.'/fulfillment-allocation/preflight', [
            'assignments' => [['order_item_id' => $item->id, 'source_code' => 'US-LA']],
        ])->assertOk()->assertJsonPath('can_submit', true)
            ->assertJsonCount(0, 'issues');
        $this->assertDatabaseMissing('fulfillment_allocations', ['order_id' => $order->id]);
        self::assertNull($order->fresh()->shipment);
    }

    public function test_preflight_and_status_readback_do_not_submit_and_report_matching_saved_assignments(): void
    {
        [$user, $order] = $this->scenario(1);
        $item = $order->items()->firstOrFail();
        $url = '/logistics/orders/'.$order->id.'/fulfillment-allocation';
        $this->actingAs($user)->getJson($url.'/status')
            ->assertOk()->assertJsonPath('status', 'none');
        $this->actingAs($user)->postJson($url.'/preflight', [
            'assignments' => [['order_item_id' => $item->id, 'source_code' => 'FACTORY']],
        ])->assertOk()->assertJsonPath('can_submit', true)->assertJsonCount(0, 'issues');
        $this->assertDatabaseMissing('fulfillment_allocations', ['order_id' => $order->id]);

        $this->actingAs($user)->postJson($url.'/manual', [
            'assignments' => [['order_item_id' => $item->id, 'source_code' => 'FACTORY']],
            'stock_exception_acknowledged' => true,
        ])->assertOk();
        $this->actingAs($user)->getJson($url.'/status')->assertOk()
            ->assertJsonPath('status', 'submitted')
            ->assertJsonPath('decision_source', 'manual')
            ->assertJsonPath('assignments.0.order_item_id', $item->id)
            ->assertJsonPath('assignments.0.source_code', 'FACTORY');
    }

    public function test_read_only_allocation_endpoints_require_operator_access(): void
    {
        [, $order] = $this->scenario(1);
        $viewer = $this->userWith(['logistics.view']);
        $item = $order->items()->firstOrFail();
        $url = '/logistics/orders/'.$order->id.'/fulfillment-allocation';
        $this->actingAs($viewer)->postJson($url.'/preflight', [
            'assignments' => [['order_item_id' => $item->id, 'source_code' => 'FACTORY']],
        ])->assertForbidden();
        $this->actingAs($viewer)->getJson($url.'/status')->assertForbidden();
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
    public function test_parent_reset_archives_original_children_and_can_materialize_fresh_generation(): void
    {
        [$actor, $parent] = $this->scenario(2);
        $parent->forceFill(['erp_order_id' => 'C99001-007'])->save();
        $assignments = [
            ['order_item_id' => $parent->items[0]->id, 'source_code' => 'US-LA'],
            ['order_item_id' => $parent->items[1]->id, 'source_code' => 'FACTORY'],
        ];
        $submit = '/logistics/orders/'.$parent->id.'/fulfillment-allocation/manual';
        $reset = '/logistics/orders/'.$parent->id.'/fulfillment-allocation/reset';
        $this->actingAs($actor)->postJson($submit, [
            'assignments' => $assignments, 'stock_exception_acknowledged' => true,
        ])->assertOk();
        $children = $parent->originalFulfillmentChildren()->orderBy('fulfillment_suffix')->get();
        $this->assertSame(['A', 'B'], $children->pluck('fulfillment_suffix')->all());
        $oldShipments = $children->map(fn ($c) => $c->shipment()->firstOrFail()->getAttributes())->all();
        $parent->refresh();
        $oldRevision = (int) $parent->workflow_revision;
        $token = \App\Modules\Logistics\Application\ParentFulfillmentReallocationService::token($parent, $parent->fulfillmentAllocation);
        $viewer = $this->userWith(['logistics.view']);
        $this->actingAs($viewer)->postJson($reset, ['confirmed' => true, 'token' => $token])->assertForbidden();
        $this->actingAs($actor)->postJson($reset, ['token' => $token])->assertUnprocessable();
        $this->actingAs($actor)->postJson($reset, ['confirmed' => true, 'token' => str_repeat('0',64)])->assertConflict();
        $this->assertCount(2, $parent->originalFulfillmentChildren()->get());
        $factory = $children->last();
        $inventory = WarehouseInventory::query()->create([
            'warehouse_id' => $factory->shipment->warehouse_id, 'product_id' => $factory->items()->firstOrFail()->product_id,
            'quantity_on_hand' => 10, 'quantity_reserved' => 1,
        ]);
        $reservationId = \Illuminate\Support\Facades\DB::table('inventory_reservations')->insertGetId([
            'reservation_key' => 'synthetic-reallocation-reservation', 'payload_hash' => str_repeat('a', 64),
            'order_id' => $factory->id, 'warehouse_id' => $inventory->warehouse_id,
            'status' => 'reserved', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('inventory_reservation_items')->insert([
            'inventory_reservation_id' => $reservationId, 'warehouse_inventory_id' => $inventory->id,
            'product_id' => $inventory->product_id, 'quantity' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($actor)->postJson($reset, ['confirmed' => true, 'token' => $token])
            ->assertOk()->assertJsonPath('archived_count', 2);
        $parent->refresh();
        $this->assertSame('pending_allocation', $parent->workflow_status);
        $this->assertDatabaseHas('inventory_reservations', ['id' => $reservationId, 'status' => 'released']);
        $this->assertSame(0, (int) $inventory->fresh()->quantity_reserved);
        $this->assertSame(10, (int) $inventory->fresh()->quantity_on_hand);
        $this->assertDatabaseHas('audit_records', ['action' => 'inventory.reservation.released', 'entity_id' => (string) $reservationId]);
        $this->assertSame('UNASSIGNED', $parent->routing_warehouse_code);
        $this->assertSame('rejected', $parent->fulfillmentAllocation->status);
        $this->assertNull($parent->fulfillmentAllocation->plan_snapshot);
        $this->assertCount(0, $parent->originalFulfillmentChildren()->get());
        $this->assertCount(2, $parent->fulfillmentChildren()->get());
        $this->actingAs($actor)->get('/logistics/wait-stock')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('orders.data.0.id', $parent->id)->where('orders.data.0.reallocation_generation', 2)
            ->where('orders.data.0.reallocation_token', null));
        foreach ($children as $index => $old) {
            $old->refresh();
            $this->assertSame(Order::SUPERSEDED_FULFILLMENT_ROLE, $old->fulfillment_role);
            $this->assertNotNull($old->operational_archived_at);
            $this->assertSame($actor->id, $old->operational_archived_by);
            $this->assertSame($oldShipments[$index], $old->shipment()->firstOrFail()->getAttributes());
            $this->assertFalse(Order::query()->eligibleForOperations()->whereKey($old->id)->exists());
        }
        $this->assertDatabaseHas('audit_records', ['action' => 'fulfillment.parent_reallocation_reset', 'entity_id' => (string) $parent->id, 'actor_id' => $actor->id]);
        $administrator = $this->userWith(['orders.view', 'system.permissions.manage']);
        $this->actingAs($administrator)->post('/orders/archive/'.$children->first()->id.'/restore')->assertConflict();
        $this->actingAs($actor)->postJson($reset, ['confirmed' => true, 'token' => $token])->assertConflict();
        $this->actingAs($actor)->postJson($submit, [
            'assignments' => $assignments, 'stock_exception_acknowledged' => true,
            'expected_workflow_revision' => $oldRevision,
        ])->assertConflict();
        $this->actingAs($actor)->postJson($submit, [
            'assignments' => $assignments, 'stock_exception_acknowledged' => true,
            'expected_workflow_revision' => $parent->workflow_revision,
        ])->assertOk();
        $this->assertSame(['A-2', 'B-2'], $parent->originalFulfillmentChildren()->orderBy('fulfillment_suffix')->pluck('fulfillment_suffix')->all());
        $this->assertCount(4, $parent->fulfillmentChildren()->get());
        $this->assertSame(['C99001-007A-2', 'C99001-007B-2'], $parent->originalFulfillmentChildren()->orderBy('fulfillment_suffix')->pluck('erp_order_id')->all());

        // A further reset must not touch the already archived generation.
        $parent->refresh();
        $token2 = \App\Modules\Logistics\Application\ParentFulfillmentReallocationService::token($parent, $parent->fulfillmentAllocation);
        $archivedBefore = $parent->fulfillmentChildren()->where('fulfillment_role', Order::SUPERSEDED_FULFILLMENT_ROLE)->orderBy('id')->get()->map->getAttributes()->all();
        $this->actingAs($actor)->postJson($reset, ['confirmed' => true, 'token' => $token2])
            ->assertOk()->assertJsonPath('archived_count', 2);
        $parent->refresh();
        $this->assertCount(0, $parent->originalFulfillmentChildren()->get());
        foreach ($archivedBefore as $old) {
            $this->assertSame($old, Order::query()->findOrFail($old['id'])->getAttributes());
        }
        $this->actingAs($actor)->postJson($submit, [
            'assignments' => $assignments, 'stock_exception_acknowledged' => true,
            'expected_workflow_revision' => $parent->workflow_revision,
        ])->assertOk();
        $this->assertSame(['A-3', 'B-3'], $parent->originalFulfillmentChildren()->orderBy('fulfillment_suffix')->pluck('fulfillment_suffix')->all());
        $this->assertCount(6, $parent->fulfillmentChildren()->get());

        // A later single-warehouse allocation can use the root identity safely.
        $parent->refresh();
        $token3 = \App\Modules\Logistics\Application\ParentFulfillmentReallocationService::token($parent, $parent->fulfillmentAllocation);
        $this->actingAs($actor)->postJson($reset, ['confirmed' => true, 'token' => $token3])->assertOk();
        $parent->refresh();
        $this->actingAs($actor)->postJson($submit, [
            'assignments' => array_map(fn ($row) => [...$row, 'source_code' => 'FACTORY'], $assignments),
            'stock_exception_acknowledged' => true,
            'expected_workflow_revision' => $parent->workflow_revision,
        ])->assertOk();
        $this->assertSame('FACTORY', $parent->fresh()->shipment->warehouse->code);
        $this->assertCount(0, $parent->originalFulfillmentChildren()->get());
        $this->assertCount(6, $parent->fulfillmentChildren()->get());
    }

    public function test_parent_reset_blocks_tracking_but_allows_previous_review_and_dispatch_without_tracking(): void
    {
        [$actor, $parent] = $this->scenario(2);
        $this->actingAs($actor)->postJson('/logistics/orders/'.$parent->id.'/fulfillment-allocation/manual', [
            'assignments' => [
                ['order_item_id' => $parent->items[0]->id, 'source_code' => 'US-LA'],
                ['order_item_id' => $parent->items[1]->id, 'source_code' => 'FACTORY'],
            ], 'stock_exception_acknowledged' => true,
        ])->assertOk();
        $children = $parent->originalFulfillmentChildren()->orderBy('fulfillment_suffix')->get();
        $children->last()->shipment()->update(['tracking_number' => 'TEST-TRACKING']);
        $parent->refresh();
        $token = \App\Modules\Logistics\Application\ParentFulfillmentReallocationService::token($parent, $parent->fulfillmentAllocation);
        $reset = '/logistics/orders/'.$parent->id.'/fulfillment-allocation/reset';
        $this->actingAs($actor)->postJson($reset, [
            'confirmed' => true, 'token' => $token,
        ])->assertConflict()->assertJsonPath('message', '履约 '.$children->last()->erp_order_id.' 已有物流单号，请人工确认是否已发货，并手工调整母订单。不能自动归档。');
        $this->assertCount(2, $parent->originalFulfillmentChildren()->get());
        $this->assertSame('submitted', $parent->fulfillmentAllocation()->firstOrFail()->status);
        $this->assertDatabaseMissing('audit_records', ['action' => 'fulfillment.parent_reallocation_reset', 'entity_id' => (string) $parent->id]);
        $children->last()->shipment()->update(['tracking_number' => null]);
        $historical = \App\Modules\Logistics\Infrastructure\Models\ShipmentAttempt::query()->create([
            'order_id' => $children->last()->id, 'attempt_number' => 1, 'shipment_status' => 'historical_evidence',
            'tracking_number' => 'HISTORICAL-TRACKING', 'snapshotted_at' => now(),
        ]);
        $this->actingAs($actor)->postJson($reset, ['confirmed' => true, 'token' => $token])->assertConflict();
        $this->assertCount(2, $parent->originalFulfillmentChildren()->get());
        // Retain a prior attempt but remove the synthetic tracking value to test the no-number branch.
        $historical->forceFill(['tracking_number' => null])->save();
        // Prior manual export status and uncertain OMS dispatch without tracking
        // are retained but are not standalone vetoes under the Owner rule.
        $factory = $children->last();
        $workflow = app(\App\Modules\Orders\Application\OrderWorkflowStatusService::class);
        $workflow->set($factory, 'sending', $factory->workflow_revision, $actor);
        $factory->refresh();
        $workflow->set($factory, 'pending_review', $factory->workflow_revision, $actor);
        $attemptId = \Illuminate\Support\Facades\DB::table('shipout_dispatch_attempts')->insertGetId([
            'order_id' => $children->first()->id, 'order_no' => $children->first()->erp_order_id,
            'provider_warehouse_id' => 'synthetic', 'payload_hash' => str_repeat('0', 64),
            'status' => 'uncertain', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $children->first()->forceFill(['order_status'=>'completed'])->save();
        $children->first()->overseaWarehouseReview()->update(['status'=>'approved']);
        $factory->shipment()->update(['tracking_number' => '   ']);
        $this->actingAs($actor)->postJson($reset, [
            'confirmed' => true, 'token' => $token,
        ])->assertOk()->assertJsonPath('archived_count',2);
        $this->assertCount(0, $parent->originalFulfillmentChildren()->get());
        $this->assertCount(2, $parent->fulfillmentChildren()->get());
        $this->assertDatabaseHas('shipout_dispatch_attempts', ['id'=>$attemptId,'status'=>'uncertain']);
        $this->assertDatabaseHas('shipment_attempts', ['id'=>$historical->id,'tracking_number'=>null]);
        $this->assertDatabaseHas('order_workflow_status_histories', ['order_id'=>$factory->id,'to_status'=>'sending']);
    }

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
