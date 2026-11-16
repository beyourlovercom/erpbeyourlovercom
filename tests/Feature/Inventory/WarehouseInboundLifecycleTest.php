<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Application\WarehouseInboundLifecycleService;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInboundBatch;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInboundItem;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventoryLot;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class WarehouseInboundLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_advances_sequentially_to_received_without_touching_inventory(): void
    {
        $warehouse = Warehouse::create(['slug'=>'lifecycle-a','code'=>'LIFE-A','name'=>'Lifecycle A']);
        $batch = WarehouseInboundBatch::create([
            'warehouse_id'=>$warehouse->id,
            'reference'=>'INBOUND-A',
            'transport_mode'=>'sea',
            'status'=>'in_transit',
            'quantity_total'=>20,
        ]);

        $service = app(WarehouseInboundLifecycleService::class);
        $service->advance($warehouse, $batch->id, 'port_arrived', null);
        $received = $service->advance($warehouse, $batch->id, 'received', null);

        $this->assertSame('received', $received->status);
        $this->assertNotNull($received->received_on);
        $this->assertDatabaseCount('warehouse_inventory', 0);
        $this->assertDatabaseCount('warehouse_inventory_lots', 0);
    }

    public function test_ready_activation_creates_sellable_inventory_and_lot_exactly_once(): void
    {
        $warehouse = Warehouse::create(['slug'=>'lifecycle-b','code'=>'LIFE-B','name'=>'Lifecycle B']);
        $product = Product::create(['sku'=>'READY-20','name'=>'Ready Product','is_active'=>true]);
        $batch = WarehouseInboundBatch::create([
            'warehouse_id'=>$warehouse->id,
            'reference'=>'INBOUND-B',
            'transport_mode'=>'air',
            'status'=>'received',
            'quantity_total'=>20,
            'received_on'=>'2026-09-19',
        ]);
        WarehouseInboundItem::create([
            'batch_id'=>$batch->id,
            'product_id'=>$product->id,
            'source_sku'=>'READY-20',
            'canonical_sku'=>'READY-20',
            'source_product_name'=>'Ready Product',
            'quantity'=>20,
            'evidence_type'=>'actual_manifest',
            'match_status'=>'matched',
        ]);

        $ready = app(WarehouseInboundLifecycleService::class)->advance($warehouse, $batch->id, 'ready', null);

        $this->assertSame('ready', $ready->status);
        $this->assertSame(20, WarehouseInventory::where('warehouse_id',$warehouse->id)->where('product_id',$product->id)->firstOrFail()->quantity_on_hand);
        $lot = WarehouseInventoryLot::where('warehouse_id',$warehouse->id)->where('product_id',$product->id)->firstOrFail();
        $this->assertSame(20, $lot->quantity);
        $this->assertSame('sellable', $lot->stock_status);

        $this->expectException(ValidationException::class);
        app(WarehouseInboundLifecycleService::class)->advance($warehouse, $batch->id, 'ready', null);
    }

    public function test_moreplus_inbound_ready_records_receipt_but_never_increments_provider_mirror(): void
    {
        $warehouse = Warehouse::query()->where('slug', 'us-la')->firstOrFail();
        $product = Product::create(['sku'=>'MP-READY-READONLY','name'=>'MorePlus Inbound','is_active'=>true]);
        $stock = WarehouseInventory::create([
            'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
            'quantity_on_hand' => 7, 'quantity_reserved' => 0,
            'provider_available_quantity' => 7, 'provider_stock_synced_at' => now(),
        ]);
        $batch = WarehouseInboundBatch::create([
            'warehouse_id' => $warehouse->id, 'reference'=>'MP-INBOUND-LEDGER',
            'transport_mode'=>'air', 'status'=>'received', 'quantity_total'=>20,
            'received_on'=>'2026-11-14',
        ]);
        WarehouseInboundItem::create([
            'batch_id' => $batch->id, 'product_id' => $product->id,
            'source_sku'=>'MP-READY-READONLY','canonical_sku'=>'MP-READY-READONLY',
            'source_product_name'=>'MorePlus Inbound','quantity'=>20,
            'evidence_type'=>'actual_manifest','match_status'=>'matched',
        ]);
        $ready = app(WarehouseInboundLifecycleService::class)->advance($warehouse, $batch->id, 'ready', null);
        $this->assertSame('ready', $ready->status);
        $this->assertSame(7, $stock->fresh()->quantity_on_hand);
        $this->assertSame(7, $stock->fresh()->quantity_available);
        $this->assertSame(0, WarehouseInventoryLot::query()->where('product_id', $product->id)->count());
        $this->assertDatabaseHas('audit_records', ['action'=>'warehouse.inbound_moreplus_stock_mirror_pending']);
    }

    public function test_unresolved_item_blocks_inventory_activation(): void
    {
        $warehouse = Warehouse::create(['slug'=>'lifecycle-c','code'=>'LIFE-C','name'=>'Lifecycle C']);
        $batch = WarehouseInboundBatch::create([
            'warehouse_id'=>$warehouse->id,
            'reference'=>'INBOUND-C',
            'transport_mode'=>'sea',
            'status'=>'received',
            'quantity_total'=>20,
            'received_on'=>'2026-09-19',
        ]);
        WarehouseInboundItem::create([
            'batch_id'=>$batch->id,
            'product_id'=>null,
            'source_sku'=>'PILLOW',
            'canonical_sku'=>null,
            'source_product_name'=>'Pillow',
            'quantity'=>20,
            'evidence_type'=>'actual_manifest',
            'match_status'=>'review',
        ]);

        try {
            app(WarehouseInboundLifecycleService::class)->advance($warehouse, $batch->id, 'ready', null);
            $this->fail('Expected unresolved inbound item to block activation.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('待复核 SKU', $e->getMessage());
        }

        $this->assertSame('received', $batch->fresh()->status);
        $this->assertDatabaseCount('warehouse_inventory', 0);
        $this->assertDatabaseCount('warehouse_inventory_lots', 0);
    }

    public function test_status_cannot_skip_a_step(): void
    {
        $warehouse = Warehouse::create(['slug'=>'lifecycle-d','code'=>'LIFE-D','name'=>'Lifecycle D']);
        $batch = WarehouseInboundBatch::create([
            'warehouse_id'=>$warehouse->id,
            'reference'=>'INBOUND-D',
            'transport_mode'=>'sea',
            'status'=>'in_transit',
            'quantity_total'=>1,
        ]);

        $this->expectException(ValidationException::class);
        app(WarehouseInboundLifecycleService::class)->advance($warehouse, $batch->id, 'received', null);
    }
}
