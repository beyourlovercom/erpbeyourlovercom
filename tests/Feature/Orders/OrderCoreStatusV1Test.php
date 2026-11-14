<?php

namespace Tests\Feature\Orders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\ShipmentService;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderProgressQuery;
use App\Modules\Orders\Application\OrderWorkflowStatusProjection;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class OrderCoreStatusV1Test extends TestCase
{
    use RefreshDatabase;

    public function test_exception_button_semantics_are_real_and_reversible_without_shipping_facts(): void
    {
        $order = $this->order();
        $actor = $this->user(['orders.view', 'orders.edit']);
        $this->actingAs($actor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'exception', 'expected_workflow_revision' => 0,
        ])->assertOk()->assertJsonPath('workflow_status.code', 'exception');
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'workflow_status' => 'exception']);
        $this->assertDatabaseMissing('order_operational_markers', ['order_id' => $order->id, 'marker' => 'exception']);
        $this->assertSame(1, $this->inQueue($order, 'exception'));
        $this->assertSame(0, $this->inQueue($order, 'pending_allocation'));
        $this->assertNull($order->fresh()->shipment);
        $this->actingAs($actor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'resolve_exception', 'expected_workflow_revision' => 1,
        ])->assertOk()->assertJsonPath('workflow_status.code', 'pending_allocation');
        $this->assertDatabaseMissing('order_operational_markers', ['order_id' => $order->id, 'marker' => 'exception']);
        $this->assertSame(1, $this->inQueue($order, 'pending_allocation'));
    }

    public function test_stockout_before_allocation_is_real_and_restock_returns_to_vita_queue(): void
    {
        $order = $this->order();
        $actor = $this->user(['orders.view', 'orders.edit']);
        $this->actingAs($actor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'out_of_stock', 'expected_workflow_revision' => 0,
        ])->assertOk()->assertJsonPath('workflow_status.code', 'out_of_stock');
        $this->assertSame(1, $this->inQueue($order, 'out_of_stock'));
        $this->assertSame(0, $this->inQueue($order, 'pending_allocation'));
        $this->actingAs($actor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'resolve_stockout', 'expected_workflow_revision' => 1,
        ])->assertOk()->assertJsonPath('workflow_status.code', 'pending_allocation');
        $this->assertSame(1, $this->inQueue($order, 'pending_allocation'));
    }
    public function test_local_cancel_requires_reason_and_is_not_cosmetic_or_a_refund(): void
    {
        $order = $this->order();
        $actor = $this->user(['orders.view', 'orders.edit']);
        $this->actingAs($actor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'cancelled', 'expected_workflow_revision' => 0,
        ])->assertStatus(422);
        $this->assertSame('pending_shipment', $order->fresh()->order_status->value);
        $this->actingAs($actor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'cancelled', 'expected_workflow_revision' => 0,
            'reason' => '客户明确要求取消订单',
        ])->assertOk()->assertJsonPath('workflow_status.code', 'cancelled');
        $this->assertSame('cancelled', $order->fresh()->order_status->value);
        $this->assertSame(1, $this->inQueue($order, 'cancelled'));
        $this->assertSame(0, $this->inQueue($order, 'pending_allocation'));
        $this->assertNull($order->fresh()->shipment);
        $this->assertDatabaseHas('order_workflow_status_histories', ['order_id' => $order->id, 'to_status' => 'cancelled']);
    }

    public function test_invalid_permission_and_stale_revision_do_not_write(): void
    {
        $order = $this->order();
        $viewer = $this->user(['orders.view']);
        $this->actingAs($viewer)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'exception', 'expected_workflow_revision' => 0,
        ])->assertForbidden();
        $editor = $this->user(['orders.view', 'orders.edit']);
        $this->actingAs($editor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'exception', 'expected_workflow_revision' => 1,
        ])->assertStatus(409);
        $this->assertSame(0, (int) $order->fresh()->workflow_revision);
        $this->assertSame(0, $this->inQueue($order, 'exception'));
    }
    public function test_shipped_order_exception_preserves_tracking_and_cancel_is_blocked(): void
    {
        $order = $this->order();
        $warehouse = Warehouse::query()->where('code', 'FACTORY')->firstOrFail();
        $order->shipment()->create([
            'warehouse_id' => $warehouse->id, 'shipment_status' => 'shipped',
            'shipped_at' => now(), 'tracking_number' => 'UAT-TRACK-123',
        ]);
        $order->operationalMarkers()->create(['marker' => 'domestic_reviewed', 'marked_at' => now()]);
        $order->forceFill(['order_status' => 'shipped'])->save();
        $editor = $this->user(['orders.view', 'orders.edit']);
        $this->actingAs($editor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'exception', 'expected_workflow_revision' => 0,
        ])->assertOk()->assertJsonPath('workflow_status.code', 'exception');
        $this->assertSame('UAT-TRACK-123', $order->fresh()->shipment->tracking_number);
        $this->actingAs($editor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'cancelled', 'expected_workflow_revision' => 1, 'reason' => '客户申请取消',
        ])->assertStatus(409);
        $this->assertSame('shipped', $order->fresh()->order_status->value);
        $this->assertSame('UAT-TRACK-123', $order->fresh()->shipment->tracking_number);
    }

    public function test_existing_marker_button_uses_core_transition_and_shipping_is_held_by_exception(): void
    {
        $order = $this->order();
        $actor = $this->user(['orders.view', 'orders.edit']);
        $this->actingAs($actor)->post('/orders/'.$order->id.'/operational-markers/exception', [
            'active' => true, 'expected_workflow_revision' => 0,
        ])->assertRedirect();
        $this->assertSame('exception', app(OrderWorkflowStatusProjection::class)->for($order->fresh())['code']);
        $this->assertSame(1, $this->inQueue($order, 'exception'));

        try {
            app(ShipmentService::class)->markShipped($order->fresh(), ['tracking_number' => 'NOT_REAL'], $actor);
            self::fail('An excepted order must not be able to ship');
        } catch (HttpException $e) {
            self::assertSame(409, $e->getStatusCode());
        }
        $this->assertNull($order->fresh()->shipment);
        $this->actingAs($actor)->post('/orders/'.$order->id.'/operational-markers/exception', [
            'active' => false, 'expected_workflow_revision' => 1,
        ])->assertRedirect();
        $this->assertSame('pending_allocation', app(OrderWorkflowStatusProjection::class)->for($order->fresh())['code']);
    }

    public function test_split_parent_exception_holds_child_queue_and_shipment_without_losing_tracking_facts(): void
    {
        $root = $this->order();
        $child = $this->order();
        $child->forceFill([
            'order_kind' => OrderKind::FulfillmentDerived,
            'fulfillment_parent_id' => $root->id,
            'fulfillment_role' => 'original',
            'fulfillment_suffix' => 'A',
        ])->save();
        $factory = Warehouse::query()->where('code', 'FACTORY')->firstOrFail();
        $child->shipment()->create(['warehouse_id' => $factory->id, 'shipment_status' => 'pending']);
        $child->operationalMarkers()->create(['marker' => 'domestic_reviewed', 'marked_at' => now()]);
        $query = app(OrderProgressQuery::class);
        self::assertSame(1, $query->readyForShipment(Order::query())->whereKey($child->id)->count());

        $editor = $this->user(['orders.view', 'orders.edit']);
        $this->actingAs($editor)->patchJson('/orders/'.$root->id.'/workflow-status', [
            'workflow_status' => 'exception', 'expected_workflow_revision' => 0,
        ])->assertOk()->assertJsonPath('workflow_status.code', 'exception');

        self::assertSame('exception', app(OrderWorkflowStatusProjection::class)->for($child->fresh())['code']);
        self::assertSame(0, $query->readyForShipment(Order::query())->whereKey($child->id)->count());
        try {
            app(ShipmentService::class)->markShipped($child->fresh(), ['tracking_number' => 'FORBIDDEN'], $editor);
            self::fail('An excepted parent must block shipment of its children');
        } catch (HttpException $e) {
            self::assertSame(409, $e->getStatusCode());
        }
        self::assertSame('pending', $child->fresh()->shipment->shipment_status);
    }

    public function test_missing_customer_info_is_an_exception_queue_hold_until_staff_resolves_it(): void
    {
        $order = $this->order();
        $actor = $this->user(['orders.view', 'orders.edit']);
        $this->actingAs($actor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'missing_info', 'expected_workflow_revision' => 0,
        ])->assertOk()
            ->assertJsonPath('workflow_status.code', 'missing_info')
            ->assertJsonPath('workflow_status.auxiliary', []);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id, 'workflow_status' => 'missing_info',
        ]);
        $this->assertDatabaseMissing('order_operational_markers', [
            'order_id' => $order->id, 'marker' => 'workflow_aux_missing_info',
        ]);
        self::assertSame(1, $this->inQueue($order, 'exception'));
        self::assertSame(0, $this->inQueue($order, 'pending_allocation'));
        self::assertSame('pending_shipment', $order->fresh()->order_status->value);
        $this->actingAs($actor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'resolve_manual_hold', 'expected_workflow_revision' => 1,
        ])->assertOk()->assertJsonPath('workflow_status.code', 'pending_allocation');
        self::assertSame(0, $this->inQueue($order, 'exception'));
        self::assertSame(1, $this->inQueue($order, 'pending_allocation'));
        $this->assertDatabaseMissing('order_operational_markers', [
            'order_id' => $order->id, 'marker' => 'workflow_aux_missing_info',
        ]);
    }

    public function test_missing_info_blocks_real_dispatch_and_keeps_split_child_out_of_ready_queue(): void
    {
        $root = $this->order();
        $child = $this->order();
        $child->forceFill([
            'order_kind' => OrderKind::FulfillmentDerived,
            'fulfillment_parent_id' => $root->id,
            'fulfillment_role' => 'original',
            'fulfillment_suffix' => 'A',
        ])->save();
        $factory = Warehouse::query()->where('code', 'FACTORY')->firstOrFail();
        $child->shipment()->create(['warehouse_id' => $factory->id, 'shipment_status' => 'pending']);
        $child->operationalMarkers()->create(['marker' => 'domestic_reviewed', 'marked_at' => now()]);
        $actor = $this->user(['orders.view', 'orders.edit']);
        $this->actingAs($actor)->patchJson('/orders/'.$root->id.'/workflow-status', [
            'workflow_status' => 'missing_info', 'expected_workflow_revision' => 0,
        ])->assertOk()->assertJsonPath('workflow_status.code', 'missing_info');
        self::assertSame('exception', app(OrderWorkflowStatusProjection::class)->for($child->fresh())['code']);
        self::assertSame(1, $this->inQueue($child, 'exception'));
        self::assertSame(0, app(OrderProgressQuery::class)->readyForShipment(Order::query())->whereKey($child->id)->count());
        try {
            app(ShipmentService::class)->markShipped($child->fresh(), ['tracking_number' => 'NOT_DISPATCHED'], $actor);
            self::fail('Missing customer info must prevent new dispatch for all child parcels');
        } catch (HttpException $e) {
            self::assertSame(409, $e->getStatusCode());
        }
        self::assertSame('pending', $child->fresh()->shipment->shipment_status);
        self::assertNull($child->fresh()->shipment->tracking_number);
    }

    public function test_legacy_missing_info_annotation_also_projects_to_exception(): void
    {
        $order = $this->order();
        $order->forceFill(['workflow_status' => 'missing_info'])->save();
        self::assertSame('missing_info', app(OrderWorkflowStatusProjection::class)->for($order->fresh())['code']);
        self::assertSame(1, $this->inQueue($order, 'exception'));
        self::assertSame(0, $this->inQueue($order, 'pending_allocation'));
    }

    public function test_changing_selected_status_replaces_old_value_and_moves_one_queue(): void
    {
        $order = $this->order();
        $actor = $this->user(['orders.view', 'orders.edit']);
        foreach ([
            ['missing_info', 'exception', 1],
            ['renew', 'exception', 2],
            ['returned_follow_up', 'returned_follow_up', 3],
            ['claims', 'exception', 4],
        ] as [$chosen, $queue, $revision]) {
            $this->actingAs($actor)->patchJson('/orders/'.$order->id.'/workflow-status', [
                'workflow_status' => $chosen, 'expected_workflow_revision' => $revision - 1,
            ])->assertOk()->assertJsonPath('workflow_status.code', $chosen)
                ->assertJsonPath('workflow_status.auxiliary', []);
            self::assertSame($chosen, $order->fresh()->workflow_status);
            self::assertSame(1, $this->inQueue($order, $queue));
            self::assertSame(0, $this->inQueue($order,
                $queue === 'exception' ? 'returned_follow_up' : 'exception'));
            self::assertSame(0, $this->inQueue($order, 'pending_allocation'));
            $this->assertDatabaseMissing('order_operational_markers', [
                'order_id' => $order->id, 'marker' => 'workflow_aux_missing_info',
            ]);
            $this->assertDatabaseHas('order_workflow_status_histories', [
                'order_id' => $order->id, 'to_status' => $chosen, 'revision' => $revision,
            ]);
        }
        self::assertSame('pending_shipment', $order->fresh()->order_status->value);
        self::assertNull($order->fresh()->shipment);
        $this->actingAs($actor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'resolve_manual_hold', 'expected_workflow_revision' => 4,
        ])->assertOk()->assertJsonPath('workflow_status.code', 'pending_allocation');
        self::assertSame(1, $this->inQueue($order, 'pending_allocation'));
        self::assertSame(0, $this->inQueue($order, 'exception'));
        self::assertSame(0, $this->inQueue($order, 'returned_follow_up'));
    }

    public function test_historic_multi_tags_are_replaced_not_stacked_when_staff_selects_one_status(): void
    {
        $order = $this->order();
        foreach (['workflow_aux_missing_info', 'workflow_aux_renew', 'workflow_aux_returned_follow_up'] as $marker) {
            $order->operationalMarkers()->create(['marker' => $marker, 'marked_at' => now()]);
        }
        self::assertCount(0, app(OrderWorkflowStatusProjection::class)->for($order->fresh())['auxiliary']);
        self::assertSame(1, $this->inQueue($order, 'exception'));
        self::assertSame(0, $this->inQueue($order, 'returned_follow_up'));

        $actor = $this->user(['orders.view', 'orders.edit']);
        $this->actingAs($actor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'returned_follow_up', 'expected_workflow_revision' => 0,
        ])->assertOk()->assertJsonPath('workflow_status.code', 'returned_follow_up')
            ->assertJsonPath('workflow_status.auxiliary', []);
        $this->assertDatabaseMissing('order_operational_markers', [
            'order_id' => $order->id, 'marker' => 'workflow_aux_missing_info',
        ]);
        $this->assertDatabaseMissing('order_operational_markers', [
            'order_id' => $order->id, 'marker' => 'workflow_aux_renew',
        ]);
        $this->assertDatabaseMissing('order_operational_markers', [
            'order_id' => $order->id, 'marker' => 'workflow_aux_returned_follow_up',
        ]);
        self::assertSame(1, $this->inQueue($order, 'returned_follow_up'));
        self::assertSame(0, $this->inQueue($order, 'exception'));
        self::assertSame(1, app(\App\Modules\Orders\Application\OrderService::class)
            ->list(['view' => 'return', 'order_kind' => 'customer'])->total());
        self::assertSame(0, app(\App\Modules\Orders\Application\OrderService::class)
            ->list(['view' => 'exception', 'order_kind' => 'customer'])->total());
    }

    public function test_unallocated_manual_stockout_can_be_replaced_by_one_new_primary_status(): void
    {
        $order = $this->order();
        $actor = $this->user(['orders.view', 'orders.edit']);
        $this->actingAs($actor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'out_of_stock', 'expected_workflow_revision' => 0,
        ])->assertOk()->assertJsonPath('workflow_status.code', 'out_of_stock');
        $this->actingAs($actor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'missing_info', 'expected_workflow_revision' => 1,
        ])->assertOk()->assertJsonPath('workflow_status.code', 'missing_info');
        self::assertSame(1, $this->inQueue($order, 'exception'));
        self::assertSame(0, $this->inQueue($order, 'out_of_stock'));
        $this->assertDatabaseHas('orders', [
            'id' => $order->id, 'workflow_status' => 'missing_info',
        ]);
        $this->assertDatabaseHas('order_workflow_status_histories', [
            'order_id' => $order->id, 'from_status' => 'out_of_stock',
            'to_status' => 'missing_info', 'revision' => 2,
        ]);
        self::assertNull($order->fresh()->shipment);
    }

    public function test_dropdown_can_return_unallocated_missing_info_to_pending_allocation(): void
    {
        $order = $this->order();
        $actor = $this->user(['orders.view', 'orders.edit']);

        $this->actingAs($actor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'missing_info', 'expected_workflow_revision' => 0,
        ])->assertOk()->assertJsonPath('workflow_status.code', 'missing_info');
        self::assertSame(1, $this->inQueue($order, 'exception'));

        $this->actingAs($actor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'pending_allocation', 'expected_workflow_revision' => 1,
        ])->assertOk()->assertJsonPath('workflow_status.code', 'pending_allocation');
        self::assertSame(1, $this->inQueue($order, 'pending_allocation'));
        self::assertSame(0, $this->inQueue($order, 'exception'));
        $this->assertDatabaseHas('orders', [
            'id' => $order->id, 'workflow_status' => null,
            'order_status' => 'pending_shipment', 'workflow_revision' => 2,
        ]);
        $this->assertDatabaseHas('order_workflow_status_histories', [
            'order_id' => $order->id, 'from_status' => 'missing_info',
            'to_status' => 'pending_allocation', 'revision' => 2,
        ]);
        self::assertNull($order->fresh()->shipment);
    }

    public function test_dropdown_return_to_allocation_clears_manual_stockout_but_never_warehouse_allocation(): void
    {
        $order = $this->order();
        $actor = $this->user(['orders.view', 'orders.edit']);
        $this->actingAs($actor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'out_of_stock', 'expected_workflow_revision' => 0,
        ])->assertOk();
        $this->actingAs($actor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'pending_allocation', 'expected_workflow_revision' => 1,
        ])->assertOk()->assertJsonPath('workflow_status.code', 'pending_allocation');
        self::assertSame(1, $this->inQueue($order, 'pending_allocation'));
        self::assertSame(0, $this->inQueue($order, 'out_of_stock'));

        $warehouse = Warehouse::query()->where('code', 'FACTORY')->firstOrFail();
        $order->shipment()->create([
            'warehouse_id' => $warehouse->id, 'shipment_status' => 'pending',
            'carrier' => '4PX',
        ]);
        $this->actingAs($actor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'pending_allocation', 'expected_workflow_revision' => 2,
        ])->assertStatus(409)->assertJsonPath('message',
            '订单已有分仓或履约记录，不能直接覆盖。请通过对应仓库的重新分仓/驳回流程退回待分仓。');
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'workflow_revision' => 2]);
        self::assertSame((int) $warehouse->id, (int) $order->fresh()->shipment->warehouse_id);
    }

    public function test_unallocated_sending_reports_precise_blocker_and_preserves_revision(): void
    {
        $order = $this->order();
        $actor = $this->user(['orders.view', 'orders.edit']);
        $this->actingAs($actor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'missing_info', 'expected_workflow_revision' => 0,
        ])->assertOk();

        $this->actingAs($actor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'sending', 'expected_workflow_revision' => 1,
        ])->assertStatus(409)->assertJsonPath('message', '订单尚未分仓，不能直接标记待发货。');
        self::assertSame('missing_info', $order->fresh()->workflow_status);
        self::assertSame(1, (int) $order->fresh()->workflow_revision);
    }

    private function inQueue(Order $order, string $phase): int
    {
        return app(OrderProgressQuery::class)->applyPhase(Order::query(), $phase)->whereKey($order->id)->count();
    }

    private function user(array $keys): User
    {
        $role = Role::create(['key' => 'core-v1-'.uniqid(), 'label' => 'Core V1 Test']);
        foreach ($keys as $key) {
            $role->permissions()->syncWithoutDetaching([
                Permission::firstOrCreate(['key' => $key], ['label' => $key])->id,
            ]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }

    private function order(): Order
    {
        return Order::query()->create([
            'erp_order_id' => 'CORE-'.uniqid(),
            'internal_order_number' => 'CORE-INT-'.uniqid(),
            'external_order_number' => 'CORE-EXT-'.uniqid(),
            'source_type' => 'manual',
            'order_kind' => OrderKind::Customer,
            'order_status' => 'pending_shipment',
            'order_date' => '2026-11-14',
            'currency' => 'USD',
        ]);
    }
}
