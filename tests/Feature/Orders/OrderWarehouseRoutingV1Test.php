<?php

namespace Tests\Feature\Orders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\ShipmentService;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Application\OrderService;
use App\Modules\Orders\Application\OrderWarehouseRouting;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class OrderWarehouseRoutingV1Test extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $warehouses = [];
        foreach ([
            'US-NY' => ['us-ny', '美东纽约仓'],
            'US-SM' => ['us-3sm', '美国3世迈仓'],
            'US-LA' => ['us-la', '美西 MorePlus 仓'],
            'US-AMZ' => ['us-amz', '亚马逊仓·美'],
            'FACTORY' => ['factory', '中国工厂'],
            'TINGON' => ['tingon', '合作-Tingon'],
            '007' => ['007', '007 本地仓库'],
        ] as $code => [$slug, $name]) {
            $warehouses[$code] = Warehouse::query()->updateOrCreate(['code' => $code], [
                'slug' => $slug, 'name' => $name, 'type' => 'warehouse', 'is_active' => true, 'priority' => 1,
            ]);
        }
        $role = Role::create(['key' => 'route-editor-'.uniqid(), 'label' => 'Route Editor']);
        foreach (['orders.view', 'orders.edit', 'logistics.view', 'procurement.view'] as $key) {
            $role->permissions()->syncWithoutDetaching([Permission::firstOrCreate(
                ['key' => $key], ['label' => $key],
            )->id]);
        }
        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach($role);
        $order = Order::query()->create([
            'erp_order_id' => 'UAT-ROUTE-'.uniqid(), 'internal_order_number' => 'INT-'.uniqid(),
            'external_order_number' => 'EXT-'.uniqid(),
            'source_type' => 'manual', 'order_kind' => 'customer',
            'order_status' => 'pending_shipment', 'order_date' => '2026-11-15',
        ]);

        return [$order, $actor, $warehouses];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('inertia.testing.ensure_pages_exist', false);
    }

    public function test_staff_route_and_pending_physical_warehouse_change_together(): void
    {
        [$order, $actor, $warehouse] = $this->fixture();
        $routing = app(OrderWarehouseRouting::class);
        self::assertSame('UNASSIGNED', $routing->project($order)['code']);
        $codes = collect($routing->options())->pluck('code')->all();
        foreach (['US-SM', 'US-LA', 'FACTORY', 'TINGON', 'OUTSOURCE', 'UNASSIGNED'] as $code) {
            self::assertContains($code, $codes);
        }
        self::assertNotContains('007', $codes);

        $this->actingAs($actor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'sending', 'expected_workflow_revision' => 0,
        ])->assertOk();
        $this->patchJson('/orders/'.$order->id.'/warehouse-route', [
            'warehouse_code' => 'US-SM', 'expected_routing_revision' => 0,
        ])->assertOk()->assertJsonPath('routing_warehouse.code', 'US-SM')
            ->assertJsonPath('routing_warehouse.revision', 1);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id, 'warehouse_id' => $warehouse['US-SM']->id, 'shipment_status' => 'pending',
            'tracking_number' => null, 'warehouse_selection_source' => 'manual',
        ]);
        self::assertSame('sending', $order->fresh()->workflow_status);
        self::assertSame(1, (int) $order->fresh()->workflow_revision);
        self::assertSame(1, Shipment::query()->where('order_id', $order->id)->count());
        // Changing the warehouse does not bypass the normal overseas review gate.
        try {
            app(ShipmentService::class)->markShipped(
                $order->fresh(), ['tracking_number' => 'SHOULD_NOT_DISPATCH', 'shipped_at' => now()], $actor
            );
            self::fail('Unreviewed warehouse must not authorize physical shipment.');
        } catch (HttpException $exception) {
            self::assertSame(409, $exception->getStatusCode());
        }

        $this->patchJson('/orders/'.$order->id.'/warehouse-route', [
            'warehouse_code' => 'US-LA', 'expected_routing_revision' => 1,
        ])->assertOk()->assertJsonPath('routing_warehouse.code', 'US-LA');
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'warehouse_id' => $warehouse['US-LA']->id]);
        self::assertSame(0, OrderWarehouseRouting::shipmentIn(Shipment::query(), 'US-SM',
            (int) $warehouse['US-SM']->id)->where('order_id', $order->id)->count());
        self::assertSame(1, OrderWarehouseRouting::shipmentIn(Shipment::query(), 'US-LA',
            (int) $warehouse['US-LA']->id)->where('order_id', $order->id)->count());

        $this->patchJson('/orders/'.$order->id.'/warehouse-route', [
            'warehouse_code' => 'FACTORY', 'expected_routing_revision' => 2,
        ])->assertOk();
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'warehouse_id' => $warehouse['FACTORY']->id]);
        self::assertSame(1, OrderWarehouseRouting::orderIn(Order::query(), 'FACTORY',
            (int) $warehouse['FACTORY']->id)->whereKey($order->id)->count());
        $this->patchJson('/orders/'.$order->id.'/warehouse-route', [
            'warehouse_code' => 'TINGON', 'expected_routing_revision' => 3,
        ])->assertOk();
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'warehouse_id' => $warehouse['TINGON']->id]);
        self::assertSame(1, OrderWarehouseRouting::shipmentIn(Shipment::query(), 'TINGON',
            (int) $warehouse['TINGON']->id)->where('order_id', $order->id)->count());
        $this->patchJson('/orders/'.$order->id.'/warehouse-route', [
            'warehouse_code' => 'OUTSOURCE', 'expected_routing_revision' => 4,
        ])->assertOk();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'routing_warehouse_code' => 'OUTSOURCE',
            'routing_revision' => 5, 'workflow_status' => 'sending']);
        $this->assertDatabaseCount('order_warehouse_route_histories', 5);
        $this->assertDatabaseHas('audit_records', [
            'entity_id' => (string) $order->id, 'action' => 'order.warehouse_route_changed',
        ]);
        $this->assertDatabaseCount('shipments', 1);
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
    }

    public function test_shipped_order_cannot_change_logical_or_physical_warehouse(): void
    {
        [$order, $actor, $warehouses] = $this->fixture();
        $order->shipment()->create([
            'warehouse_id' => $warehouses['US-SM']->id, 'shipment_status' => 'shipped',
            'tracking_number' => 'REAL123', 'shipped_at' => now(),
        ]);
        $routing = app(OrderWarehouseRouting::class);
        self::assertSame('US-SM', $routing->project($order->fresh())['code']);
        $this->actingAs($actor)->patchJson('/orders/'.$order->id.'/warehouse-route', [
            'warehouse_code' => 'US-LA', 'expected_routing_revision' => 0,
        ])->assertStatus(409);
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id,
            'warehouse_id' => $warehouses['US-SM']->id, 'tracking_number' => 'REAL123',
            'shipment_status' => 'shipped']);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id, 'routing_warehouse_code' => null, 'routing_revision' => 0,
        ]);
    }

    public function test_route_plus_status_drives_tingon_partner_and_outsource_lists(): void
    {
        [$order, $actor] = $this->fixture();
        $this->actingAs($actor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'sending', 'expected_workflow_revision' => 0,
        ])->assertOk();

        $this->patchJson('/orders/'.$order->id.'/warehouse-route', [
            'warehouse_code' => 'TINGON', 'expected_routing_revision' => 0,
        ])->assertOk();
        $this->get('/logistics/tingon?view=pending')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders.total', 1)
                ->where('orders.data.0.routing_warehouse.code', 'TINGON')
                ->where('orders.data.0.can_edit', true));
        $this->get('/orders/collaboration')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders.total', 1)
                ->where('orders.data.0.routing_warehouse.code', 'TINGON'));

        $this->patchJson('/orders/'.$order->id.'/warehouse-route', [
            'warehouse_code' => 'OUTSOURCE', 'expected_routing_revision' => 1,
        ])->assertOk();
        $this->get('/logistics/tingon?view=pending')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders.total', 0));
        $this->get('/orders/collaboration')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders.total', 0));
        $this->get('/orders/procurement')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders.total', 1)
                ->where('orders.data.0.routing_warehouse.code', 'OUTSOURCE'));
        $this->get('/purchase/external')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.total', 1)
                ->where('rows.data.0.routing_warehouse.code', 'OUTSOURCE'));
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id,
            'warehouse_id' => null, 'shipment_status' => 'pending', 'tracking_number' => null]);
    }

    public function test_oms_filter_matches_manual_warehouse_and_excludes_stale_physical_warehouse(): void
    {
        [$order, $actor, $warehouses] = $this->fixture();
        $order->shipment()->create([
            'warehouse_id' => $warehouses['US-SM']->id,
            'shipment_status' => 'pending',
        ]);
        $this->actingAs($actor)->patchJson('/orders/'.$order->id.'/warehouse-route', [
            'warehouse_code' => 'US-LA', 'expected_routing_revision' => 0,
        ])->assertOk();
        $service = app(OrderService::class);
        self::assertSame(1, $service->list(['oms_grid_columns' => ['grid_warehouse' => 'MorePlus']])->total());
        self::assertSame(0, $service->list(['oms_grid_columns' => ['grid_warehouse' => '世迈']])->total());
        self::assertSame(1, $service->list(['warehouse_route_code' => 'US-LA'])->total());
        self::assertSame(0, $service->list(['warehouse_route_code' => 'US-SM'])->total());
        $this->patchJson('/orders/'.$order->id.'/warehouse-route', [
            'warehouse_code' => 'US-AMZ', 'expected_routing_revision' => 1,
        ])->assertOk()->assertJsonPath('target_url',
            '/orders?warehouse_route_code=US-AMZ&view=pending_review');
        $this->assertDatabaseHas('orders', [
            'id' => $order->id, 'routing_warehouse_code' => 'US-AMZ',
        ]);
        self::assertSame(1, $service->list(['warehouse_route_code' => 'US-AMZ'])->total());
        self::assertSame(0, $service->list(['warehouse_route_code' => 'US-LA'])->total());
    }

    public function test_manual_route_moves_real_shipment_and_keeps_other_details(): void
    {
        [$order, $actor, $warehouses] = $this->fixture();
        $order->shipment()->create([
            'warehouse_id' => $warehouses['US-SM']->id,
            'shipment_status' => 'pending', 'warehouse_selection_source' => 'manual',
        ]);
        $this->actingAs($actor)->patchJson('/orders/'.$order->id.'/warehouse-route', [
            'warehouse_code' => 'US-NY', 'expected_routing_revision' => 0,
        ])->assertOk();

        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id, 'warehouse_id' => $warehouses['US-NY']->id,
            'warehouse_selection_source' => 'manual', 'shipment_status' => 'pending',
        ]);
    }

    public function test_warehouse_dropdown_targets_exact_new_warehouse_and_status_workbench(): void
    {
        [$order, $actor, $warehouses] = $this->fixture();
        $order->shipment()->create(['warehouse_id' => $warehouses['US-SM']->id,
            'shipment_status' => 'pending', 'warehouse_selection_source' => 'manual']);
        $this->actingAs($actor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'sending', 'expected_workflow_revision' => 0,
        ])->assertOk();
        $route = $this->patchJson('/orders/'.$order->id.'/warehouse-route', [
            'warehouse_code' => 'US-NY', 'expected_routing_revision' => 0,
        ])->assertOk()->assertJsonPath('routing_warehouse.code', 'US-NY')
            ->assertJsonPath('target_url', '/logistics/oversea/us-ny?view=pending&global_grid=1');
        self::assertSame('US-NY', $route->json('routing_warehouse.code'));
        $this->get('/logistics/oversea/us-3sm?view=pending')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rows.total', 0)->where('viewCounts.pending', 0));
        $this->get('/logistics/oversea/us-ny?view=pending')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rows.total', 1)->where('viewCounts.pending', 1)
            ->where('rows.data.0.routing_warehouse.code', 'US-NY'));

        foreach ([
            ['out_of_stock', 'stockout', 1],
            ['exception', 'exceptions', 2],
            ['missing_info', 'exceptions', 3],
            ['sending', 'pending', 4],
        ] as [$status, $bucket, $revision]) {
            $this->patchJson('/orders/'.$order->id.'/workflow-status', [
                'workflow_status' => $status, 'expected_workflow_revision' => $revision,
            ])->assertOk()
                ->assertJsonPath('target_url', '/logistics/oversea/us-ny?view='.$bucket.'&global_grid=1');
            $this->get('/logistics/oversea/us-ny?view='.$bucket)
                ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('rows.total', 1));
            if ($bucket !== 'pending') {
                $this->get('/logistics/oversea/us-ny?view=pending')
                    ->assertOk()->assertInertia(fn (Assert $page) => $page
                    ->where('rows.total', 0));
            }
        }
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id, 'warehouse_id' => $warehouses['US-NY']->id,
            'shipment_status' => 'pending',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id, 'routing_warehouse_code' => 'US-NY',
            'workflow_status' => 'sending',
        ]);
    }

    public function test_warehouse_and_exception_state_route_to_real_domestic_and_tingon_buckets(): void
    {
        [$order, $actor] = $this->fixture();
        $this->actingAs($actor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'exception', 'expected_workflow_revision' => 0,
        ])->assertOk();
        $this->patchJson('/orders/'.$order->id.'/warehouse-route', [
            'warehouse_code' => 'FACTORY', 'expected_routing_revision' => 0,
        ])->assertOk()->assertJsonPath('target_url', '/logistics/china?view=exceptions&global_grid=1');
        $this->get('/logistics/china?view=exceptions')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('rows.total', 1));
        $this->patchJson('/orders/'.$order->id.'/warehouse-route', [
            'warehouse_code' => 'TINGON', 'expected_routing_revision' => 1,
        ])->assertOk()->assertJsonPath('target_url', '/logistics/tingon?view=exceptions');
        $this->get('/logistics/tingon?view=exceptions')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('orders.total', 1));
        $this->get('/logistics/china?view=exceptions')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('rows.total', 0));
        $this->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'out_of_stock', 'expected_workflow_revision' => 1,
        ])->assertOk()->assertJsonPath('target_url', '/logistics/tingon?view=stockout');
        $this->get('/logistics/tingon?view=stockout')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('orders.total', 1));
        $this->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'pending_allocation', 'expected_workflow_revision' => 2,
        ])->assertOk()->assertJsonPath('target_url', '/logistics/wait-stock?global_grid=1');
        $this->get('/logistics/tingon?view=stockout')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('orders.total', 0));
    }

    public function test_same_route_repairs_legacy_mismatch_and_restores_domestic_pending(): void
    {
        [$order, $actor, $warehouses] = $this->fixture();
        $order->forceFill(['routing_warehouse_code' => 'FACTORY', 'routing_revision' => 1])->save();
        $order->shipment()->create([
            'warehouse_id' => $warehouses['US-SM']->id, 'shipment_status' => 'pending',
        ]);
        $this->actingAs($actor)->patchJson('/orders/'.$order->id.'/warehouse-route', [
            'warehouse_code' => 'FACTORY', 'expected_routing_revision' => 1,
        ])->assertOk()->assertJsonPath('routing_warehouse.revision', 2);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id, 'warehouse_id' => $warehouses['FACTORY']->id,
            'shipment_status' => 'pending',
        ]);
        $this->get('/logistics/china?view=pending')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('rows.total', 1));
        $this->assertDatabaseHas('order_warehouse_route_histories', [
            'order_id' => $order->id, 'from_code' => 'FACTORY', 'to_code' => 'FACTORY', 'revision' => 2,
        ]);
    }

    public function test_reassign_one_leg_updates_allocation_and_invalidates_prior_overseas_approval(): void
    {
        [$order, $actor, $warehouses] = $this->fixture();
        $order->shipment()->create([
            'warehouse_id' => $warehouses['US-SM']->id, 'shipment_status' => 'pending',
            'carrier' => 'MyCarrier', 'service' => 'Custom', 'carrier_selection_source' => 'manual',
        ]);
        $order->fulfillmentAllocation()->create([
            'status' => 'submitted', 'decision_source' => 'manual',
            'plan_snapshot' => [
                'is_split' => false, 'plan_type' => 'single',
                'segments' => [['source_code' => 'US-SM', 'warehouse_id' => $warehouses['US-SM']->id,
                    'warehouse_name' => 'Old', 'procurement_required' => false, 'items' => []]],
                'derived_orders' => [['order_id' => $order->id, 'warehouse_id' => $warehouses['US-SM']->id]],
            ],
            'manual_assignments' => [['order_item_id' => 91, 'source_code' => 'US-SM']],
        ]);
        $order->overseaWarehouseReview()->create([
            'status' => 'approved', 'progress' => 'sending_pending',
            'submitted_warehouse_id' => $warehouses['US-SM']->id,
            'reviewed_warehouse_id' => $warehouses['US-SM']->id,
            'reviewed_by' => $actor->id, 'reviewed_at' => now(),
        ]);

        $this->actingAs($actor)->patchJson('/orders/'.$order->id.'/warehouse-route', [
            'warehouse_code' => 'US-NY', 'expected_routing_revision' => 0,
        ])->assertOk();
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id, 'warehouse_id' => $warehouses['US-NY']->id,
            'carrier' => 'MyCarrier', 'service' => 'Custom', 'carrier_selection_source' => 'manual',
        ]);
        $allocation = $order->fresh()->fulfillmentAllocation;
        self::assertSame('US-NY', $allocation->plan_snapshot['segments'][0]['source_code']);
        self::assertSame($warehouses['US-NY']->id, $allocation->plan_snapshot['segments'][0]['warehouse_id']);
        self::assertSame('US-NY', $allocation->manual_assignments[0]['source_code']);
        $this->assertDatabaseHas('oversea_warehouse_reviews', [
            'order_id' => $order->id, 'status' => 'pending',
            'submitted_warehouse_id' => $warehouses['US-NY']->id,
            'reviewed_warehouse_id' => null,
        ]);
    }

    public function test_pending_shipment_with_tracking_cannot_be_rerouted(): void
    {
        [$order, $actor, $warehouses] = $this->fixture();
        $order->shipment()->create([
            'warehouse_id' => $warehouses['US-SM']->id,
            'shipment_status' => 'pending', 'tracking_number' => 'EXTERNAL123',
        ]);
        $this->actingAs($actor)->patchJson('/orders/'.$order->id.'/warehouse-route', [
            'warehouse_code' => 'FACTORY', 'expected_routing_revision' => 0,
        ])->assertStatus(409);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id, 'warehouse_id' => $warehouses['US-SM']->id,
            'tracking_number' => 'EXTERNAL123',
        ]);
        $this->assertDatabaseCount('order_warehouse_route_histories', 0);
    }

    public function test_split_order_cannot_be_rerouted_from_order_dropdown(): void
    {
        [$order, $actor, $warehouses] = $this->fixture();
        $child = Order::query()->create([
            'erp_order_id' => 'CHILD-'.uniqid(), 'internal_order_number' => 'CHILD-'.uniqid(),
            'external_order_number' => 'CHILD-'.uniqid(),
            'source_type' => 'manual', 'order_kind' => 'customer', 'order_status' => 'pending_shipment',
            'order_date' => '2026-11-17', 'fulfillment_parent_id' => $order->id, 'fulfillment_role' => 'original',
        ]);
        $this->actingAs($actor)->patchJson('/orders/'.$order->id.'/warehouse-route', [
            'warehouse_code' => 'FACTORY', 'expected_routing_revision' => 0,
        ])->assertStatus(409);
        $this->assertDatabaseMissing('shipments', ['order_id' => $order->id]);
    }

    public function test_version_permissions_bad_options_and_archived_routes_are_rejected_without_mutation(): void
    {
        [$order, $actor] = $this->fixture();
        $viewer = User::factory()->create(['is_active' => true]);
        $this->actingAs($viewer)->patchJson('/orders/'.$order->id.'/warehouse-route', [
            'warehouse_code' => 'US-SM', 'expected_routing_revision' => 0,
        ])->assertForbidden();
        $this->actingAs($actor)->patchJson('/orders/'.$order->id.'/warehouse-route', [
            'warehouse_code' => '007', 'expected_routing_revision' => 0,
        ])->assertStatus(422);
        $this->patchJson('/orders/'.$order->id.'/warehouse-route', [
            'warehouse_code' => 'US-SM', 'expected_routing_revision' => 1,
        ])->assertStatus(409);
        $this->assertDatabaseCount('order_warehouse_route_histories', 0);
        $order->forceFill(['archived_at' => now()])->save();
        $this->patchJson('/orders/'.$order->id.'/warehouse-route', [
            'warehouse_code' => 'US-SM', 'expected_routing_revision' => 0,
        ])->assertStatus(409);
    }
}
