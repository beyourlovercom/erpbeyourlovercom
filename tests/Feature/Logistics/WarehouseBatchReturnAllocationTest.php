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

    public function test_overseas_approved_and_stockout_reset_review_warehouse_and_do_not_modify_other_warehouse(): void
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
            'order_ids' => [$approved->id, $stockout->id, $factory->id],
        ])->assertOk()->assertJsonPath('success_count', 2)->assertJsonPath('failed_count', 1)
            ->assertJsonPath('results.2.ok', false);
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

    public function test_permissions_invalid_payload_and_genuine_dispatch_fact_cannot_be_overwritten(): void
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
            ->assertOk()->assertJsonPath('success_count', 0)->assertJsonPath('failed_count', 1);
        $this->assertSame('REAL-DISPATCH', $order->fresh()->shipment->tracking_number);
        $this->assertNotNull($order->fresh()->shipment->warehouse_id);
        $this->assertDatabaseMissing('audit_records', ['action' => 'logistics.bulk_return_allocation', 'entity_id' => (string) $order->id]);
        $this->actingAs($oversea)->postJson('/logistics/oversea/batch-return-allocation', ['order_ids' => [$order->id]])
            ->assertOk()->assertJsonPath('failed_count', 1);
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
