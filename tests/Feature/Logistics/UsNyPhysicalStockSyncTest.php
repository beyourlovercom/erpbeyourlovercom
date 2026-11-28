<?php

namespace Tests\Feature\Logistics;

use App\Modules\Inventory\Application\InventoryAvailabilityProjection;
use App\Modules\Inventory\Application\InventoryReservationService;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Logistics\Application\Xlwms\UsNyPhysicalStockSyncService;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsInventorySnapshot;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductWarehouseSku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class UsNyPhysicalStockSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_stock_applies_and_keeps_actual_allocation_reserved_across_hourly_refresh(): void
    {
        config()->set('xlwms.us_ny_inventory_physical_apply_enabled', true);
        $ny = Warehouse::query()->where('code', 'US-NY')->firstOrFail();
        $p = Product::query()->create(['sku' => 'NY-CANONICAL', 'name' => 'NY', 'is_active' => true]);
        ProductWarehouseSku::query()->create(['product_id' => $p->id, 'warehouse_id' => $ny->id,
            'sku' => 'NY-PHYSICAL', 'is_active' => true, 'verified_at' => now()]);
        $snapshot = $this->snapshot($ny, $p);
        $svc = app(UsNyPhysicalStockSyncService::class);
        $preview = $svc->reconcileSnapshot($snapshot->id);
        self::assertSame(1, $preview['verified_skus']);
        self::assertCount(2, $preview['quarantined']);
        self::assertSame(0, WarehouseInventory::query()->where('warehouse_id', $ny->id)->count());
        $svc->reconcileSnapshot($snapshot->id, true);
        $stock = WarehouseInventory::query()->where('warehouse_id', $ny->id)->where('product_id', $p->id)->firstOrFail();
        self::assertSame(10, $stock->quantity_on_hand);
        $order = Order::create(['internal_order_number' => 'NY-TEST-1', 'source_type' => 'manual',
            'order_kind' => 'customer', 'order_date' => now()->toDateString(), 'order_status' => 'pending_shipment']);
        $order->items()->create(['product_id' => $p->id, 'sku' => 'NY-PHYSICAL', 'product_summary' => 'NY',
            'quantity' => 1, 'unit_price' => '1.00', 'line_revenue' => '1.00']);
        $reservation = app(InventoryReservationService::class)->reserveAsSystem($order->id, $ny->id, 'ny-test');
        app(InventoryReservationService::class)->reserveAsSystem($order->id, $ny->id, 'ny-test');
        self::assertSame(1, $stock->fresh()->quantity_reserved);
        self::assertSame(7, app(InventoryAvailabilityProjection::class)->forRows(collect([$stock->fresh()]))[$stock->id]);
        $gate = app(\App\Modules\Logistics\Application\Xlwms\UsNyAllocationReservation::class);
        self::assertSame([], $gate->dispatchBlockers($order));
        $other = Order::create(['internal_order_number' => 'NY-TEST-2', 'source_type' => 'manual',
            'order_kind' => 'customer', 'order_date' => now()->toDateString(), 'order_status' => 'pending_shipment']);
        $other->items()->create(['product_id' => $p->id, 'sku' => 'NY-PHYSICAL', 'product_summary' => 'NY',
            'quantity' => 8, 'unit_price' => '1.00', 'line_revenue' => '8.00']);
        self::assertSame(['US_NY_STOCK_RESERVED_OR_INSUFFICIENT'], $gate->dispatchBlockers($other));
        $svc->reconcileSnapshot($snapshot->id, true);
        self::assertSame(1, $stock->fresh()->quantity_reserved);
        self::assertSame(7, app(InventoryAvailabilityProjection::class)->forRows(collect([$stock->fresh()]))[$stock->id]);
        app(\App\Modules\Inventory\Application\Contracts\InventoryReservationRepository::class)
            ->commit($reservation['id'], null);
        self::assertSame(0, $stock->fresh()->quantity_reserved);
        self::assertSame(7, app(InventoryAvailabilityProjection::class)->forRows(collect([$stock->fresh()]))[$stock->id]);
        self::assertSame(1, DB::table('inventory_reservations')->count());
    }

    public function test_stale_or_disabled_snapshot_cannot_apply(): void
    {
        $ny = Warehouse::query()->where('code', 'US-NY')->firstOrFail();
        $p = Product::query()->create(['sku' => 'NY-STALE', 'name' => 'NY']);
        $s = $this->snapshot($ny, $p);
        $s->forceFill(['fetched_at' => now()->subHour()])->save();
        config()->set('xlwms.us_ny_inventory_physical_apply_enabled', true);
        $this->expectExceptionMessage('US_NY_UNTRUSTED_SNAPSHOT');
        app(UsNyPhysicalStockSyncService::class)->reconcileSnapshot($s->id, true);
    }

    private function snapshot(Warehouse $ny, Product $p): XlwmsInventorySnapshot
    {
        $s = XlwmsInventorySnapshot::query()->create(['warehouse_id' => $ny->id, 'wh_code' => 'US01',
            'checksum' => str_repeat('a', 64), 'status' => 'blocked', 'fetched_at' => now(),
            'provider_sku_count' => 3, 'mapped_sku_count' => 1, 'unmatched_sku_count' => 2,
            'blocked_sku_count' => 1, 'summary' => ['v2' => true], 'mismatches' => []]);
        foreach ([['NY-PHYSICAL', $p->id, 'matched', 10, 8, 2],
            ['UNVERIFIED', null, 'unmatched', 499, 499, 0], ['ZERO', null, 'unmatched', 0, 0, 0]] as [$sku,$id,$status,$total,$free,$locked]) {
            $s->rows()->create(['provider_sku' => $sku, 'product_id' => $id, 'resolution_status' => $status,
                'oms_total' => $total, 'oms_available' => $free, 'oms_locked' => $locked]);
        }
        return $s;
    }
}
