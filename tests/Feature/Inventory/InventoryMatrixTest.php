<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Application\InventoryMatrixService;
use App\Modules\Inventory\Application\WarehouseInboundLifecycleService;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInboundBatch;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInboundItem;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InventoryMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_matrix_counts_only_matched_sea_in_transit_and_never_turns_missing_into_zero(): void
    {
        $warehouse = Warehouse::where('code','US-NY')->firstOrFail();
        $product = Product::create(['sku'=>'MATRIX-SKU','name'=>'Name','material_full_name'=>'Material','is_active'=>true]);
        WarehouseInventory::create(['warehouse_id'=>$warehouse->id,'product_id'=>$product->id,'quantity_on_hand'=>51,'quantity_reserved'=>7,'expected_restock_qty'=>99]);
        $factory = Warehouse::where('code','FACTORY')->firstOrFail();
        WarehouseInventory::create(['warehouse_id'=>$factory->id,'product_id'=>$product->id,'quantity_on_hand'=>0,'quantity_reserved'=>0]);
        $this->batch($warehouse,$product,'SEA-A','sea','in_transit',30);
        $this->batch($warehouse,$product,'SEA-B','sea','port_arrived',5);
        $this->batch($warehouse,$product,'SEA-C','sea','received',8);
        $this->batch($warehouse,$product,'SEA-D','sea','ready',100);
        $this->batch($warehouse,$product,'SEA-E','sea','preparing',100);
        $this->batch($warehouse,$product,'SEA-F','sea','factory_packing',100);
        $this->batch($warehouse,$product,'AIR-A','air','in_transit',100);
        $this->batch($warehouse,$product,'UNMATCHED','sea','in_transit',100,'unmatched');

        $row = $this->row($product);
        $this->assertSame('Material',$row['material_name']);
        $this->assertSame(InventoryMatrixService::WAREHOUSE_CODES,array_keys($row['warehouses']));
        $this->assertSame(['on_hand'=>51,'transit'=>35,'pending'=>8],$row['warehouses']['US-NY']);
        $this->assertSame(['on_hand'=>0,'transit'=>0,'pending'=>0],$row['warehouses']['FACTORY']);
        $this->assertSame(['on_hand'=>null,'transit'=>0,'pending'=>0],$row['warehouses']['DE']);
    }

    public function test_batch_activation_transfers_quantity_once_and_moreplus_does_not_inflate_mirror(): void
    {
        $product = Product::create(['sku'=>'MATRIX-ACTIVATION','name'=>'Activation','is_active'=>true]);
        $ny = Warehouse::where('code','US-NY')->firstOrFail();
        $mp = Warehouse::where('code','US-LA')->firstOrFail();
        foreach ([$ny,$mp] as $warehouse) {
            WarehouseInventory::create(['warehouse_id'=>$warehouse->id,'product_id'=>$product->id,'quantity_on_hand'=>51,'quantity_reserved'=>0]);
        }
        $batch = $this->batch($ny,$product,'NY-SEA','sea','in_transit',30);
        $moreplus = $this->batch($mp,$product,'MP-SEA','sea','received',30);
        $lifecycle = app(WarehouseInboundLifecycleService::class);
        $lifecycle->advance($ny,$batch->id,'port_arrived',null);
        $this->assertSame(['on_hand'=>51,'transit'=>30,'pending'=>0],$this->row($product)['warehouses']['US-NY']);
        $lifecycle->advance($ny,$batch->id,'received',null);
        $this->assertSame(['on_hand'=>51,'transit'=>0,'pending'=>30],$this->row($product)['warehouses']['US-NY']);
        $lifecycle->advance($ny,$batch->id,'ready',null);
        $this->assertSame(['on_hand'=>81,'transit'=>0,'pending'=>0],$this->row($product)['warehouses']['US-NY']);
        $lifecycle->advance($mp,$moreplus->id,'ready',null);
        $this->assertSame(['on_hand'=>51,'transit'=>0,'pending'=>0],$this->row($product)['warehouses']['US-LA']);
    }

    public function test_lowercase_moreplus_code_resolves_to_canonical_matrix_column(): void
    {
        $warehouse = Warehouse::where('code','US-LA')->firstOrFail();
        $warehouse->update(['code'=>'us-la']);
        $product = Product::create(['sku'=>'MATRIX-MP-LOWER','name'=>'Lowercase warehouse','is_active'=>true]);
        WarehouseInventory::create(['warehouse_id'=>$warehouse->id,'product_id'=>$product->id,'quantity_on_hand'=>51,'quantity_reserved'=>0]);
        $this->batch($warehouse,$product,'MP-SEA-LOWER','sea','in_transit',30);
        $this->assertSame(['on_hand'=>51,'transit'=>30,'pending'=>0],$this->row($product)['warehouses']['US-LA']);
    }

    private function row(Product $product): array
    {
        return collect(app(InventoryMatrixService::class)->rows())->firstWhere('id',$product->id);
    }

    private function batch(Warehouse $warehouse, Product $product, string $reference, string $mode, string $status, int $quantity, string $match='matched'): WarehouseInboundBatch
    {
        $batch = WarehouseInboundBatch::create(['warehouse_id'=>$warehouse->id,'reference'=>$reference,'transport_mode'=>$mode,'status'=>$status,'quantity_total'=>$quantity]);
        WarehouseInboundItem::create(['batch_id'=>$batch->id,'product_id'=>$product->id,'source_sku'=>$product->sku,'canonical_sku'=>$product->sku,'quantity'=>$quantity,'match_status'=>$match]);
        return $batch;
    }
}
