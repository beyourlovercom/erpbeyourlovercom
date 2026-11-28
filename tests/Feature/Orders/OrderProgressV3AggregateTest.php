<?php

namespace Tests\Feature\Orders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Orders\Application\OrderProgressQuery;
use App\Modules\Orders\Application\OrderService;
use App\Modules\Orders\Application\OrderWarehouseRouting;
use App\Modules\Orders\Application\OrderWorkflowStatusProjection;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OrderProgressV3AggregateTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_split_segments_roll_up_without_overwriting_either_segment(): void
    {
        $root = $this->order('ROOT');
        $root->fulfillmentAllocation()->create(['status' => 'submitted', 'decision_source' => 'manual']);
        $a = $this->order('A', $root);
        $b = $this->order('B', $root);
        $factory = Warehouse::where('code', 'FACTORY')->firstOrFail();
        $overseas = Warehouse::where('code', 'US-LA')->firstOrFail();
        $a->shipment()->create(['warehouse_id' => $factory->id, 'carrier' => '4PX', 'shipment_status' => 'pending']);
        $b->shipment()->create(['warehouse_id' => $overseas->id, 'carrier' => 'USPS', 'shipment_status' => 'pending']);
        self::assertSame('allocated', $this->phase($root));
        $a->operationalMarkers()->create(['marker' => 'domestic_reviewed', 'marked_at' => now()]);
        $a->shipment->update(['shipment_status' => 'shipped', 'shipped_at' => now(), 'tracking_number' => 'TEST-A']);
        self::assertSame('partially_shipped', $this->phase($root));
        self::assertSame('shipping', $this->phase($a));
        self::assertSame('pending_review', $this->phase($b));
        self::assertSame(1, app(OrderProgressQuery::class)->applyPhase(Order::query(), 'partially_shipped')->whereKey($root->id)->count());
        $b->shipment->overseaReview()->create(['status' => 'approved', 'reviewed_warehouse_id' => $overseas->id, 'reviewed_at' => now()]);
        $b->shipment->update(['shipment_status' => 'shipped', 'shipped_at' => now(), 'tracking_number' => 'TEST-B']);
        self::assertSame('shipping', $this->phase($root));
        self::assertSame('shipping', $this->phase($b));
    }

    public function test_unassigned_active_split_leg_is_visible_as_exception_without_reopening_parent(): void
    {
        $root = $this->order('COMPLETED-ROOT');
        $root->forceFill(['order_status' => 'completed'])->save();
        $leg = $this->order('UNASSIGNED-LEG', $root);
        $leg->forceFill(['workflow_status' => 'pending_allocation', 'routing_warehouse_code' => 'UNASSIGNED'])->save();
        $leg->shipment()->create(['shipment_status' => 'pending']);
        self::assertSame('exception', $this->phase($leg->fresh()));
        $queries = app(OrderProgressQuery::class);
        self::assertSame(1, $queries->applyPhase(Order::query(), 'exception')->whereKey($leg->id)->count());
        self::assertSame(0, $queries->pendingAllocation(Order::query())->whereKey($leg->id)->count());
        self::assertTrue(app(OrderService::class)->list(['view' => 'exception'])->getCollection()->contains('id', $leg->id));
        self::assertSame('/orders?view=exception', app(OrderWarehouseRouting::class)->destination($leg->fresh()));
        self::assertSame('completed', $root->fresh()->order_status->value);
        self::assertSame('pending', $leg->fresh()->shipment->shipment_status);
        $leg->shipment()->update(['tracking_number' => 'EXISTING-DO-NOT-RESEND']);
        self::assertSame('exception', $this->phase($leg->fresh()));
        self::assertSame(1, $queries->applyPhase(Order::query(), 'exception')->whereKey($leg->id)->count());
        self::assertSame('EXISTING-DO-NOT-RESEND', $leg->fresh()->shipment->tracking_number);
    }

    public function test_submitted_allocation_preserves_sent_factory_shipment_without_review_marker(): void
    {
        // A historical 4PX shipment with no Tracking and no Kaven review
        // remains sent, not an artificial approval conflict.
        $root = $this->order('ORPHAN-ALLOCATED');
        $root->fulfillmentAllocation()->create(['status' => 'submitted', 'decision_source' => 'manual']);
        $factory = Warehouse::where('code', 'FACTORY')->firstOrFail();
        $root->shipment()->create([
            'warehouse_id' => $factory->id, 'carrier' => '4PX',
            'shipment_status' => 'shipped', 'shipped_at' => now(),
        ]);

        self::assertSame('shipped', $root->fresh()->shipment?->shipment_status);
        self::assertSame('sent', $this->phase($root));
        self::assertSame(0, app(OrderProgressQuery::class)->applyPhase(Order::query(), 'allocated')
            ->whereKey($root->id)->count());
        self::assertSame(1, app(OrderProgressQuery::class)->applyPhase(Order::query(), 'sent')
            ->whereKey($root->id)->count());
        self::assertSame('shipped', $root->fresh()->shipment->shipment_status);

        $root->shipment->update(['shipment_status' => 'pending', 'shipped_at' => null]);
        self::assertSame('allocated', $this->phase($root));
        self::assertSame(1, app(OrderProgressQuery::class)->applyPhase(Order::query(), 'allocated')
            ->whereKey($root->id)->count());
    }

    public function test_unreviewed_sent_split_child_rolls_up_as_partially_shipped(): void
    {
        $root = $this->order('CONFLICT-ROOT');
        $root->fulfillmentAllocation()->create(['status' => 'submitted', 'decision_source' => 'manual']);
        $a = $this->order('CONFLICT-A', $root);
        $b = $this->order('CONFLICT-B', $root);
        $factory = Warehouse::where('code', 'FACTORY')->firstOrFail();
        $a->shipment()->create([
            'warehouse_id' => $factory->id, 'carrier' => '4PX',
            'shipment_status' => 'shipped', 'shipped_at' => now(),
        ]);
        $b->shipment()->create([
            'warehouse_id' => $factory->id, 'carrier' => '4PX', 'shipment_status' => 'pending',
        ]);
        self::assertSame('sent', $this->phase($a));
        self::assertSame('partially_shipped', $this->phase($root));
        self::assertSame(1, app(OrderProgressQuery::class)
            ->applyPhase(Order::query(), 'partially_shipped')->whereKey($root->id)->count());
        self::assertSame(0, app(OrderProgressQuery::class)
            ->applyPhase(Order::query(), 'progress_conflict')->whereKey($root->id)->count());
    }

    public function test_single_primary_follow_up_replaces_legacy_status_and_restores_shipping_facts(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'v3-editor', 'label' => 'Editor']);
        foreach (['orders.view', 'orders.edit'] as $key) {
            $role->permissions()->syncWithoutDetaching([Permission::firstOrCreate(['key' => $key], ['label' => $key])->id]);
        }
        $user->roles()->attach($role);
        $order = $this->order('AUX');
        $factory = Warehouse::where('code', 'FACTORY')->firstOrFail();
        $order->shipment()->create(['warehouse_id' => $factory->id, 'carrier' => '4PX', 'shipment_status' => 'shipped', 'shipped_at' => now(), 'tracking_number' => 'TEST-123']);
        $order->operationalMarkers()->create(['marker' => 'domestic_reviewed', 'marked_at' => now()]);
        $order->forceFill(['workflow_status' => 'freight_product_cost_follow_up'])->save();
        self::assertSame('freight_product_cost_follow_up', $this->phase($order));
        $this->actingAs($user)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'renew', 'expected_workflow_revision' => 0,
        ])->assertOk()->assertJsonPath('workflow_status.code', 'renew')
            ->assertJsonPath('workflow_status.auxiliary', []);
        self::assertSame('renew', $this->phase($order));
        self::assertSame(1, app(OrderService::class)
            ->list(['workflow_status' => 'renew'])->total());
        self::assertSame(0, app(OrderService::class)
            ->list(['workflow_status' => 'freight_product_cost_follow_up'])->total());
        $this->actingAs($user)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'resolve_manual_hold', 'expected_workflow_revision' => 1,
        ])->assertOk()->assertJsonPath('workflow_status.code', 'shipping');
        self::assertSame([], app(OrderWorkflowStatusProjection::class)->for($order->fresh())['auxiliary']);
        self::assertSame('shipping', $this->phase($order));
        self::assertSame('shipped', $order->fresh()->shipment->shipment_status);
        self::assertSame('TEST-123', $order->fresh()->shipment->tracking_number);
    }

    public function test_legacy_split_root_and_shipped_child_labels_cannot_reopen_dispatch(): void
    {
        $root = $this->order('STALE-ROOT');
        $root->forceFill(['workflow_status' => 'pending_allocation'])->save();
        $a = $this->order('STALE-A', $root);
        $b = $this->order('STALE-B', $root);
        $c = $this->order('STALE-C', $root);
        $factory = Warehouse::where('code', 'FACTORY')->firstOrFail();
        $a->forceFill(['workflow_status' => 'sending'])->save();
        $a->shipment()->create(['warehouse_id' => $factory->id, 'shipment_status' => 'pending']);
        $b->forceFill(['workflow_status' => 'out_of_stock'])->save();
        $b->shipment()->create(['warehouse_id' => $factory->id, 'shipment_status' => 'pending']);
        $c->forceFill(['workflow_status' => 'sending', 'order_status' => 'shipped'])->save();
        $c->shipment()->create(['warehouse_id' => $factory->id, 'shipment_status' => 'shipped', 'shipped_at' => now(), 'tracking_number' => 'TRACK-C']);
        $q = app(OrderProgressQuery::class);
        self::assertSame('partially_shipped', $this->phase($root));
        self::assertSame('shipping', $this->phase($c));
        self::assertFalse($q->pendingAllocation(Order::query())->whereKey($root->id)->exists());
        self::assertFalse($q->readyForShipment(Order::query())->whereKey($c->id)->exists());
        self::assertTrue($q->applyPhase(Order::query(), 'partially_shipped')->whereKey($root->id)->exists());
        self::assertFalse(app(\App\Modules\Logistics\Application\PendingAllocationEntryPolicy::class)->allows($root));
        $root->forceFill(['workflow_status' => 'sending'])->save();
        self::assertFalse($q->readyForShipment(Order::query())->whereKey($root->id)->exists());
        $b->forceFill(['workflow_status' => 'sending'])->save();
        self::assertSame('partially_shipped', $this->phase($root));
        self::assertSame('TRACK-C', $c->fresh()->shipment->tracking_number);
    }

    private function phase(Order $order): string
    {
        return app(OrderWorkflowStatusProjection::class)->for($order->fresh())['code'];
    }

    private function order(string $suffix, ?Order $parent = null): Order
    {
        return Order::create([
            'fulfillment_parent_id' => $parent?->id, 'fulfillment_suffix' => $parent ? $suffix : null,
            'fulfillment_role' => $parent ? 'original' : null,
            'internal_order_number' => 'V3-INT-'.$suffix,
            'erp_order_id' => 'V3-AGG-'.$suffix,
            'external_order_number' => 'V3-EXT-'.$suffix,
            'source_type' => 'manual', 'order_kind' => $parent ? 'fulfillment_derived' : 'customer',
            'order_date' => '2026-11-05', 'order_status' => 'pending_shipment', 'currency' => 'USD',
        ]);
    }
}
