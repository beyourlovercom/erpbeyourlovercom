<?php

namespace Tests\Feature\Logistics;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\WarehouseShipmentProjection;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WarehouseShipmentProjectionArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_warehouse_shipment_projection_excludes_all_archived_and_trashed_orders_and_children(): void
    {
        $warehouse = Warehouse::query()->where('code', 'FACTORY')->firstOrFail();
        $active = $this->order('active');
        $deleted = $this->order('deleted');
        $deleted->delete();
        $preserved = $this->order('preserved');
        $preserved->forceFill(['archived_at' => now()])->save();
        $operational = $this->order('operational');
        $operational->forceFill(['operational_archived_at' => now()])->save();

        $parent = $this->order('parent');
        $child = $this->order('child', $parent->id);
        $parent->forceFill(['operational_archived_at' => now()])->save();

        foreach ([$active, $deleted, $preserved, $operational, $child] as $order) {
            $order->shipment()->create([
                'warehouse_id' => $warehouse->id,
                'shipment_status' => 'pending',
            ]);
        }

        $projection = app(WarehouseShipmentProjection::class)->forWarehouse($warehouse);
        $this->assertSame(1, $projection['counts']['total']);
        $this->assertSame(1, $projection['pagination']['total']);
        $this->assertCount(1, $projection['rows']);
        $this->assertSame($active->id, $projection['rows'][0]['order_id']);

        foreach ([$deleted, $preserved, $operational, $child] as $excluded) {
            $searched = app(WarehouseShipmentProjection::class)->forWarehouse(
                $warehouse, query: $excluded->erp_order_id
            );
            $this->assertSame(0, $searched['pagination']['total']);
        }
    }

    private function order(string $suffix, ?int $parentId = null): Order
    {
        return Order::create([
            'erp_order_id' => '007-TEST-'.strtoupper($suffix),
            'internal_order_number' => 'INT-'.strtoupper($suffix),
            'source_type' => 'manual',
            'order_kind' => $parentId ? 'fulfillment_derived' : 'customer',
            'fulfillment_parent_id' => $parentId,
            'order_status' => 'pending_shipment',
            'order_date' => '2026-11-12',
            'currency' => 'USD',
        ]);
    }
}
