<?php

namespace Tests\Feature\Orders;

use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Orders\Application\OrderProgressQuery;
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
        $root->fulfillmentAllocation()->create(['status'=>'submitted','decision_source'=>'manual']);
        $a = $this->order('A', $root);
        $b = $this->order('B', $root);
        $factory = Warehouse::where('code','FACTORY')->firstOrFail();
        $overseas = Warehouse::where('code','US-LA')->firstOrFail();
        $a->shipment()->create(['warehouse_id'=>$factory->id,'carrier'=>'4PX','shipment_status'=>'pending']);
        $b->shipment()->create(['warehouse_id'=>$overseas->id,'carrier'=>'USPS','shipment_status'=>'pending']);
        self::assertSame('allocated', $this->phase($root));
        $a->operationalMarkers()->create(['marker'=>'domestic_reviewed','marked_at'=>now()]);
        $a->shipment->update(['shipment_status'=>'shipped','shipped_at'=>now(),'tracking_number'=>'TEST-A']);
        self::assertSame('partially_shipped', $this->phase($root));
        self::assertSame('shipping', $this->phase($a));
        self::assertSame('pending_review', $this->phase($b));
        self::assertSame(1, app(OrderProgressQuery::class)->applyPhase(Order::query(),'partially_shipped')->whereKey($root->id)->count());
        $b->shipment->overseaReview()->create(['status'=>'approved','reviewed_warehouse_id'=>$overseas->id,'reviewed_at'=>now()]);
        $b->shipment->update(['shipment_status'=>'shipped','shipped_at'=>now(),'tracking_number'=>'TEST-B']);
        self::assertSame('shipping', $this->phase($root));
        self::assertSame('shipping', $this->phase($b));
    }

    public function test_submitted_allocation_cannot_hide_existing_shipment_exception(): void
    {
        // Mirrors a historical 4PX source order with submitted allocation,
        // shipped Shipment, no Tracking and no Kaven review marker.
        $root = $this->order('ORPHAN-ALLOCATED');
        $root->fulfillmentAllocation()->create(['status'=>'submitted','decision_source'=>'manual']);
        $factory = Warehouse::where('code','FACTORY')->firstOrFail();
        $root->shipment()->create([
            'warehouse_id'=>$factory->id,'carrier'=>'4PX',
            'shipment_status'=>'shipped','shipped_at'=>now(),
        ]);

        self::assertSame('shipped', $root->fresh()->shipment?->shipment_status);
        self::assertSame('progress_conflict', $this->phase($root));
        self::assertSame(0, app(OrderProgressQuery::class)->applyPhase(Order::query(),'allocated')
            ->whereKey($root->id)->count());
        self::assertSame(1, app(OrderProgressQuery::class)->applyPhase(Order::query(),'progress_conflict')
            ->whereKey($root->id)->count());
        self::assertSame('shipped', $root->fresh()->shipment->shipment_status);

        $root->shipment->update(['shipment_status'=>'pending','shipped_at'=>null]);
        self::assertSame('allocated', $this->phase($root));
        self::assertSame(1, app(OrderProgressQuery::class)->applyPhase(Order::query(),'allocated')
            ->whereKey($root->id)->count());
    }

    public function test_conflicting_split_child_is_not_misreported_as_partially_shipped(): void
    {
        $root = $this->order('CONFLICT-ROOT');
        $root->fulfillmentAllocation()->create(['status'=>'submitted','decision_source'=>'manual']);
        $a = $this->order('CONFLICT-A', $root);
        $b = $this->order('CONFLICT-B', $root);
        $factory = Warehouse::where('code','FACTORY')->firstOrFail();
        $a->shipment()->create([
            'warehouse_id'=>$factory->id,'carrier'=>'4PX',
            'shipment_status'=>'shipped','shipped_at'=>now(),
        ]);
        $b->shipment()->create([
            'warehouse_id'=>$factory->id,'carrier'=>'4PX','shipment_status'=>'pending',
        ]);
        self::assertSame('progress_conflict', $this->phase($a));
        self::assertSame('progress_conflict', $this->phase($root));
        self::assertSame(0, app(OrderProgressQuery::class)
            ->applyPhase(Order::query(),'partially_shipped')->whereKey($root->id)->count());
        self::assertSame(1, app(OrderProgressQuery::class)
            ->applyPhase(Order::query(),'progress_conflict')->whereKey($root->id)->count());
    }

    public function test_single_primary_follow_up_replaces_legacy_status_and_restores_shipping_facts(): void
    {
        $user = User::factory()->create(['is_active'=>true]);
        $role = \App\Models\Role::create(['key'=>'v3-editor','label'=>'Editor']);
        foreach (['orders.view','orders.edit'] as $key) {
            $role->permissions()->syncWithoutDetaching([\App\Models\Permission::firstOrCreate(['key'=>$key],['label'=>$key])->id]);
        }
        $user->roles()->attach($role);
        $order = $this->order('AUX');
        $factory = Warehouse::where('code','FACTORY')->firstOrFail();
        $order->shipment()->create(['warehouse_id'=>$factory->id,'carrier'=>'4PX','shipment_status'=>'shipped','shipped_at'=>now(),'tracking_number'=>'TEST-123']);
        $order->operationalMarkers()->create(['marker'=>'domestic_reviewed','marked_at'=>now()]);
        $order->forceFill(['workflow_status'=>'freight_product_cost_follow_up'])->save();
        self::assertSame('freight_product_cost_follow_up', $this->phase($order));
        $this->actingAs($user)->patchJson('/orders/'.$order->id.'/workflow-status',[
            'workflow_status'=>'renew','expected_workflow_revision'=>0,
        ])->assertOk()->assertJsonPath('workflow_status.code','renew')
            ->assertJsonPath('workflow_status.auxiliary', []);
        self::assertSame('renew', $this->phase($order));
        self::assertSame(1, app(\App\Modules\Orders\Application\OrderService::class)
            ->list(['workflow_status' => 'renew'])->total());
        self::assertSame(0, app(\App\Modules\Orders\Application\OrderService::class)
            ->list(['workflow_status' => 'freight_product_cost_follow_up'])->total());
        $this->actingAs($user)->patchJson('/orders/'.$order->id.'/workflow-status',[
            'workflow_status'=>'resolve_manual_hold','expected_workflow_revision'=>1,
        ])->assertOk()->assertJsonPath('workflow_status.code','shipping');
        self::assertSame([], app(OrderWorkflowStatusProjection::class)->for($order->fresh())['auxiliary']);
        self::assertSame('shipping', $this->phase($order));
        self::assertSame('shipped',$order->fresh()->shipment->shipment_status);
        self::assertSame('TEST-123',$order->fresh()->shipment->tracking_number);
    }

    private function phase(Order $order): string
    {
        return app(OrderWorkflowStatusProjection::class)->for($order->fresh())['code'];
    }

    private function order(string $suffix, ?Order $parent = null): Order
    {
        return Order::create([
            'fulfillment_parent_id'=>$parent?->id,'fulfillment_suffix'=>$parent?$suffix:null,
            'fulfillment_role'=>$parent?'original':null,
            'internal_order_number'=>'V3-INT-'.$suffix,
            'erp_order_id'=>'V3-AGG-'.$suffix,
            'external_order_number'=>'V3-EXT-'.$suffix,
            'source_type'=>'manual','order_kind'=>$parent?'fulfillment_derived':'customer',
            'order_date'=>'2026-11-05','order_status'=>'pending_shipment','currency'=>'USD',
        ]);
    }
}
