<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Infrastructure\EloquentInventoryAvailabilityReader;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GlobalSkuStockIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_moreplus_synced_sku_remains_available_when_another_sku_is_unsynced(): void
    {
        config()->set('inventory_sku_gate.enabled', true);
        $warehouse = Warehouse::query()->where('code','US-LA')->firstOrFail();
        $good = Product::query()->firstOrCreate(['sku'=>'GLOBAL-MP-GOOD'],['name'=>'Good']);
        $bad = Product::query()->firstOrCreate(['sku'=>'GLOBAL-MP-UNKNOWN'],['name'=>'Unknown']);
        WarehouseInventory::query()->create([
            'warehouse_id'=>$warehouse->id,'product_id'=>$good->id,
            'quantity_on_hand'=>10,'quantity_reserved'=>2,
            'provider_stock_synced_at'=>now(),'provider_available_quantity'=>9,
        ]);
        WarehouseInventory::query()->create([
            'warehouse_id'=>$warehouse->id,'product_id'=>$bad->id,
            'quantity_on_hand'=>90,'quantity_reserved'=>0,
        ]);
        $rows=app(EloquentInventoryAvailabilityReader::class)->forProductIds([$good->id,$bad->id]);
        $goodRow=collect($rows[$good->id])->firstWhere('warehouse_id',$warehouse->id);
        $badRow=collect($rows[$bad->id])->firstWhere('warehouse_id',$warehouse->id);
        self::assertSame(7,$goodRow['quantity_available']);
        self::assertNull($goodRow['stock_gate_reason']);
        self::assertSame(0,$badRow['quantity_available']);
        self::assertSame('provider_sku_not_synced',$badRow['stock_gate_reason']);
        self::assertSame(10,(int)WarehouseInventory::where('product_id',$good->id)->value('quantity_on_hand'));
    }

    public function test_manual_verified_warehouse_uses_only_existing_product_row_not_full_import(): void
    {
        config()->set('inventory_sku_gate.enabled', true);
        $warehouse=Warehouse::query()->where('code','US-NY')->firstOrFail();
        $available=Product::query()->firstOrCreate(['sku'=>'GLOBAL-NY-AVAILABLE'],['name'=>'Available']);
        $missing=Product::query()->firstOrCreate(['sku'=>'GLOBAL-NY-MISSING'],['name'=>'Missing']);
        WarehouseInventory::query()->create([
            'warehouse_id'=>$warehouse->id,'product_id'=>$available->id,
            'quantity_on_hand'=>6,'quantity_reserved'=>1,
        ]);
        $reader=app(EloquentInventoryAvailabilityReader::class);
        $meta=collect($reader->activeWarehouses())->firstWhere('id',$warehouse->id);
        self::assertSame('manual',$meta['inventory_state']);
        self::assertTrue($meta['can_auto_allocate']);
        $stocks=$reader->forProductIds([$available->id,$missing->id]);
        $goodRow=collect($stocks[$available->id])->firstWhere('warehouse_id',$warehouse->id);
        $badRow=collect($stocks[$missing->id])->firstWhere('warehouse_id',$warehouse->id);
        self::assertSame(5,$goodRow['quantity_available']);
        self::assertNull($badRow['quantity_available']);
    }

    public function test_amazon_warehouse_automatic_routing_remains_explicitly_disabled(): void
    {
        config()->set('inventory_sku_gate.enabled', true);
        $amz=Warehouse::query()->where('code','US-AMZ')->firstOrFail();
        $product=Product::query()->firstOrCreate(['sku'=>'GLOBAL-AMZ-SKU'],['name'=>'Amazon']);
        WarehouseInventory::query()->create([
            'warehouse_id'=>$amz->id,'product_id'=>$product->id,
            'quantity_on_hand'=>392,'quantity_reserved'=>0,
        ]);
        $reader=app(EloquentInventoryAvailabilityReader::class);
        self::assertFalse(collect($reader->activeWarehouses())->firstWhere('id',$amz->id)['can_auto_allocate']);
    }
}
