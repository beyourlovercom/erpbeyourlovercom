<?php

namespace Tests\Feature\Logistics;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\LogisticsStatusSummary;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderSourceType;
use App\Modules\Orders\Application\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WarehouseBatchReturnAllocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_domestic_review_stockout_and_pending_are_forcibly_returned_to_real_allocation_queue(): void
    {
        $actor = $this->actor(['logistics.view', 'domestic.review']);
        $review = $this->order('FACTORY', 'pending_review');
        $stockout = $this->order('FACTORY', 'out_of_stock');
        $pending = $this->order('FACTORY', 'sending');
        $review->shipment->forceFill(['tracking_number' => 'STAGED-NOT-SHIPPED'])->save();
        $stockout->operationalMarkers()->create(['marker' => 'domestic_stockout', 'marked_by' => $actor->id, 'marked_at' => now()]);
        $pending->operationalMarkers()->create(['marker' => 'domestic_reviewed', 'marked_by' => $actor->id, 'marked_at' => now()]);

        $this->actingAs($actor)->postJson('/logistics/china/batch-return-allocation', [
            'order_ids' => [$review->id, $stockout->id, $pending->id],
        ])->assertOk()->assertJsonPath('success_count', 3)->assertJsonPath('failed_count', 0);

        foreach ([$review, $stockout, $pending] as $order) {
            $fresh = $order->fresh('shipment');
            $this->assertSame('pending_allocation', $fresh->workflow_status);
            $this->assertSame('UNASSIGNED', $fresh->routing_warehouse_code);
            $this->assertSame('pending_shipment', $fresh->order_status->value);
            $this->assertNull($fresh->shipment->warehouse_id);
            $this->assertNull($fresh->shipment->carrier);
            $this->assertNull($fresh->shipment->service);
            $this->assertNull($fresh->shipment->tracking_number);
            $this->assertTrue(app(LogisticsStatusSummary::class)->pendingAllocationQuery()->whereKey($order->id)->exists());
            $this->assertDatabaseHas('audit_records', ['action' => 'logistics.bulk_return_allocation', 'entity_id' => (string) $order->id]);
            $this->assertDatabaseMissing('order_operational_markers', ['order_id' => $order->id, 'marker' => 'domestic_stockout']);
            $this->assertDatabaseMissing('order_operational_markers', ['order_id' => $order->id, 'marker' => 'domestic_reviewed']);
        }
    }

    public function test_overseas_approved_and_stockout_reset_review_and_leave_unselected_warehouses_untouched(): void
    {
        $actor = $this->actor(['logistics.view', 'logistics.operate']);
        $approved = $this->order('US-NY', 'sending');
        $stockout = $this->order('US-NY', 'out_of_stock');
        $factory = $this->order('FACTORY', 'pending_review');
        foreach ([$approved, $stockout] as $order) {
            $order->overseaWarehouseReview()->create([
                'status' => $order->is($approved) ? 'approved' : 'stockout',
                'submitted_warehouse_id' => $order->shipment->warehouse_id,
                'reviewed_warehouse_id' => $order->shipment->warehouse_id,
                'reviewed_by' => $actor->id, 'reviewed_at' => now(),
            ]);
        }
        $this->actingAs($actor)->postJson('/logistics/oversea/batch-return-allocation', [
            'order_ids' => [$approved->id, $stockout->id],
        ])->assertOk()->assertJsonPath('success_count', 2)->assertJsonPath('failed_count', 0);
        foreach ([$approved, $stockout] as $order) {
            $fresh = $order->fresh(['shipment', 'overseaWarehouseReview']);
            $this->assertSame('pending_allocation', $fresh->workflow_status);
            $this->assertNull($fresh->shipment->warehouse_id);
            $this->assertSame('pending', $fresh->overseaWarehouseReview->status);
            $this->assertNull($fresh->overseaWarehouseReview->reviewed_warehouse_id);
            $this->assertNull($fresh->overseaWarehouseReview->submitted_warehouse_id);
        }
        $this->assertNotNull($factory->fresh()->shipment->warehouse_id);
    }

    public function test_permissions_payload_and_dispatched_shipment_force_return_with_immutable_attempt(): void
    {
        $viewer = $this->actor(['logistics.view']);
        $domestic = $this->actor(['logistics.view', 'domestic.review']);
        $oversea = $this->actor(['logistics.view', 'logistics.operate']);
        $order = $this->order('FACTORY', 'sending');
        $url = '/logistics/china/batch-return-allocation';
        $this->actingAs($viewer)->postJson($url, ['order_ids' => [$order->id]])->assertForbidden();
        $this->actingAs($viewer)->postJson('/logistics/oversea/batch-return-allocation', ['order_ids' => [$order->id]])->assertForbidden();
        $this->actingAs($domestic)->postJson($url, ['order_ids' => [$order->id, $order->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('order_ids.1');

        $order->shipment->forceFill(['shipment_status' => 'shipped', 'shipped_at' => now(), 'tracking_number' => 'REAL-DISPATCH'])->save();
        $this->actingAs($domestic)->postJson($url, ['order_ids' => [$order->id]])
            ->assertOk()->assertJsonPath('success_count', 1)->assertJsonPath('failed_count', 0);
        $this->assertNull($order->fresh()->shipment->tracking_number);
        $this->assertNull($order->fresh()->shipment->warehouse_id);
        $this->assertSame('pending', $order->fresh()->shipment->shipment_status);
        $this->assertNull($order->fresh()->shipment->shipped_at);
        $this->assertDatabaseHas('shipment_attempts', [
            'order_id' => $order->id, 'shipment_status' => 'shipped', 'tracking_number' => 'REAL-DISPATCH',
        ]);
        $this->assertDatabaseHas('audit_records', ['action' => 'logistics.bulk_return_allocation', 'entity_id' => (string) $order->id]);
        $this->actingAs($oversea)->postJson('/logistics/oversea/batch-return-allocation', ['order_ids' => [$order->id]])
            ->assertOk()->assertJsonPath('success_count', 1);
        $this->assertTrue(app(LogisticsStatusSummary::class)->pendingAllocationQuery()->whereKey($order->id)->exists());
    }

    public function test_archived_and_completed_woo_orders_are_reactivated_without_woo_writes(): void
    {
        $actor = $this->actor(['logistics.view', 'domestic.review', 'logistics.operate']);
        $archived = $this->order('FACTORY', 'completed');
        $archived->forceFill(['archived_at' => now(), 'operational_archived_at' => now(),
            'operational_archived_by' => $actor->id, 'order_status' => OrderStatus::Completed])->save();
        $archived->delete();
        $woo = $this->order('US-NY', 'completed');
        $woo->forceFill(['source_type' => OrderSourceType::WooCommerce, 'order_status' => OrderStatus::Completed])->save();
        $woo->financials()->create(['provider_order_status' => 'completed', 'currency' => 'USD', 'paid_at' => now()]);
        $woo->shipment->forceFill(['shipment_status' => 'completed', 'shipped_at' => now(),
            'tracking_number' => 'REAL-WOO-TRACK'])->save();

        $this->actingAs($actor)->postJson('/logistics/china/batch-return-allocation', [
            'order_ids' => [$archived->id],
        ])->assertOk()->assertJsonPath('success_count', 1);
        $this->actingAs($actor)->postJson('/logistics/oversea/batch-return-allocation', [
            'order_ids' => [$woo->id],
        ])->assertOk()->assertJsonPath('success_count', 1);

        $restored = Order::query()->findOrFail($archived->id);
        $this->assertNull($restored->archived_at);
        $this->assertNull($restored->operational_archived_at);
        $this->assertNull($restored->deleted_at);
        $this->assertSame('pending_allocation', $restored->workflow_status);
        $this->assertNull($restored->shipment->warehouse_id);
        $this->assertSame('pending', $woo->fresh()->shipment->shipment_status);
        $this->assertNull($woo->fresh()->shipment->warehouse_id);
        $this->assertSame('completed', $woo->fresh()->financials->provider_order_status);
        $this->assertDatabaseHas('shipment_attempts', ['order_id' => $woo->id,
            'shipment_status' => 'completed', 'tracking_number' => 'REAL-WOO-TRACK']);
        $this->assertTrue(app(LogisticsStatusSummary::class)->pendingAllocationQuery()->whereKey($archived->id)->exists());
        $this->assertTrue(app(LogisticsStatusSummary::class)->pendingAllocationQuery()->whereKey($woo->id)->exists());
    }

    public function test_split_child_returns_through_parent_queue_without_resetting_sibling_until_parent_selected(): void
    {
        $actor = $this->actor(['logistics.view', 'domestic.review', 'logistics.operate']);
        $parent = $this->order('FACTORY', 'sending');
        $a = $this->order('US-NY', 'sending');
        $b = $this->order('US-NY', 'sending');
        foreach (['A' => $a, 'B' => $b] as $suffix => $child) {
            $child->forceFill([
                'fulfillment_parent_id' => $parent->id, 'fulfillment_suffix' => $suffix,
                'fulfillment_role' => 'original', 'order_kind' => OrderKind::FulfillmentDerived,
            ])->save();
        }
        $b->shipment->forceFill(['shipment_status' => 'shipped', 'shipped_at' => now(),
            'tracking_number' => 'SIBLING-DISPATCH'])->save();

        $this->actingAs($actor)->postJson('/logistics/oversea/batch-return-allocation', [
            'order_ids' => [$a->id],
        ])->assertOk()->assertJsonPath('success_count', 1);
        $this->assertNull($a->fresh()->shipment->warehouse_id);
        $this->assertSame('pending_allocation', $a->fresh()->workflow_status);
        $this->assertSame('pending_allocation', $parent->fresh()->workflow_status);
        $this->assertNotNull($b->fresh()->shipment->warehouse_id);
        $this->assertTrue(app(LogisticsStatusSummary::class)->pendingAllocationQuery()->whereKey($parent->id)->exists());

        $this->actingAs($actor)->postJson('/logistics/china/batch-return-allocation', [
            'order_ids' => [$parent->id],
        ])->assertOk()->assertJsonPath('success_count', 1);
        $this->assertNull($b->fresh()->shipment->warehouse_id);
        $this->assertSame('pending', $b->fresh()->shipment->shipment_status);
        $this->assertDatabaseHas('shipment_attempts', ['order_id' => $b->id,
            'shipment_status' => 'shipped', 'tracking_number' => 'SIBLING-DISPATCH']);
    }

    private function actor(array $permissions): User
    {
        $role = Role::query()->create(['key' => 'batch-return-'.uniqid(), 'label' => 'Batch Return']);
        foreach ($permissions as $key) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    private function order(string $warehouseCode, string $workflow): Order
    {
        $warehouse = Warehouse::query()->where('code', $warehouseCode)->firstOrFail();
        $order = Order::query()->create([
            'erp_order_id' => '007-BATCH-'.uniqid(), 'internal_order_number' => 'INT-'.uniqid(),
            'external_order_number' => 'EXT-'.uniqid(),
            'source_type' => OrderSourceType::Manual, 'order_kind' => OrderKind::Customer,
            'order_date' => '2026-11-19', 'order_status' => OrderStatus::PendingShipment,
            'workflow_status' => $workflow, 'routing_warehouse_code' => $warehouseCode,
            'currency' => 'USD',
        ]);
        $order->shipment()->create([
            'warehouse_id' => $warehouse->id, 'shipment_status' => 'pending',
            'carrier' => $warehouseCode === 'FACTORY' ? '4PX' : 'USPS',
            'service' => 'Standard', 'warehouse_selection_source' => 'manual',
        ]);

        return $order->fresh('shipment');
    }
}
