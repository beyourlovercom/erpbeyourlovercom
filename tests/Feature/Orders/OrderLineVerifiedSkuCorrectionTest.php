<?php

namespace Tests\Feature\Orders;

use App\Models\User;
use App\Modules\Audit\Infrastructure\Models\AuditRecord;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Orders\Application\OrderLineSkuCorrectionService;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductSkuAlias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class OrderLineVerifiedSkuCorrectionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Order,1:int,2:User} */
    private function fixture(): array
    {
        $p = Product::query()->firstOrCreate(['sku' => '609A'], [
            'name' => 'ROSEY', 'product_type' => 'simple', 'is_active' => true,
        ]);
        $gift = Product::query()->firstOrCreate(['sku' => 'gift'], [
            'name' => 'A LOVELY GIFT', 'product_type' => 'simple', 'is_active' => true,
        ]);
        ProductSkuAlias::query()->firstOrCreate(['alias_sku' => 'VX609A'], [
            'product_id' => $p->id,
            'match_key' => 'vx609a',
            'source_type' => 'manual',
            'notes' => 'Legacy alias; must stay unchanged',
        ]);
        $order=Order::query()->create([
            'internal_order_number'=>'TEST-SKU-'.uniqid(),
            'erp_order_id'=>'ERP-SKU-'.uniqid(),
            'source_type'=>'woocommerce','order_kind'=>'customer',
            'external_order_number'=>'C41691-007',
            'order_date'=>'2026-10-22','order_status'=>'pending_shipment',
        ]);
        $line=$order->items()->create([
            'product_id'=>$gift->id,
            'woocommerce_line_item_id'=>555001,
            'sku'=>'gift',
            'source_sku'=>'gift',
            'quantity'=>1,
            'unit_price'=>'0.00',
            'line_revenue'=>'0.00',
            'product_summary'=>'A LOVELY GIFT',
        ]);
        return [$order,(int)$line->id,User::factory()->create()];
    }

    public function test_exact_spreadsheet_sku_updates_only_one_line_preserving_woo_lineage_and_global_alias(): void
    {
        [$order,$lineId,$actor]=$this->fixture();
        $result=app(OrderLineSkuCorrectionService::class)
            ->correct($order->id,$lineId,'gift','VX609A',$actor);
        $line=$order->items()->findOrFail($lineId);
        $this->assertSame('VX609A',$line->sku);
        $this->assertSame('VX609A',$line->resolved_fulfillment_sku);
        $this->assertSame('gift',$line->source_sku);
        $this->assertSame(555001,$line->woocommerce_line_item_id);
        $this->assertSame(1,$line->quantity);
        $this->assertSame('0.00',$line->unit_price);
        $this->assertSame('609A',$line->product->sku);
        $this->assertSame('VX609A',ProductSkuAlias::query()->sole()->alias_sku);
        $this->assertSame($lineId,$result['item_id']);
        $this->assertSame('pending_shipment',$order->fresh()->order_status->value);
        $this->assertDatabaseHas('audit_records',[
            'action'=>'order.item.fulfillment_sku_corrected',
            'reason'=>'tencent_shipping_sheet_verified',
            'entity_id'=>(string)$lineId,
        ]);
    }

    public function test_stale_item_and_second_attempt_fail_without_mutations(): void
    {
        [$order,$lineId,$actor]=$this->fixture();
        $service=app(OrderLineSkuCorrectionService::class);
        $service->correct($order->id,$lineId,'gift','VX609A',$actor);
        $this->expectException(HttpException::class);
        $service->correct($order->id,$lineId,'gift','VX609A',$actor);
    }

    public function test_cannot_correct_a_line_after_shipment_starts(): void
    {
        [$order,$lineId,$actor]=$this->fixture();
        $warehouse=Warehouse::query()->firstOrCreate(['code'=>'US-SM'], [
            'slug'=>'us-3sm','name'=>'世迈仓','type'=>'warehouse','is_active'=>true,
        ]);
        $order->shipment()->create([
            'shipment_status'=>'pending','warehouse_id'=>$warehouse->id,
            'carrier'=>'TEST','service'=>'TEST','carrier_selection_source'=>'manual',
        ]);
        $this->expectException(HttpException::class);
        app(OrderLineSkuCorrectionService::class)
            ->correct($order->id,$lineId,'gift','VX609A',$actor);
    }

    public function test_non_admin_cannot_use_order_line_sku_correction_endpoint(): void
    {
        [$order,$lineId,$actor]=$this->fixture();
        $this->actingAs($actor)->patchJson("/orders/{$order->id}/items/{$lineId}/verified-sku", [
            'expected_sku'=>'gift','target_sku'=>'VX609A',
        ])->assertForbidden();
        $this->assertSame('gift',$order->items()->findOrFail($lineId)->sku);
        $this->assertDatabaseCount('audit_records',0);
    }
}
