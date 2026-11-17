<?php

namespace Tests\Feature\Logistics;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\OverseaFiles\MorePlusOutboundExporter;
use App\Modules\Logistics\Application\OverseaFiles\Wms64OutboundExporter;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Application\OutboundOrderSkuResolver;
use App\Modules\Products\Application\SkuMatchKey;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductSkuAlias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

final class OutboundOrderSkuAliasRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $warehouseCode): Order
    {
        $warehouse = Warehouse::query()->updateOrCreate(['code' => $warehouseCode], [
            'name' => $warehouseCode, 'slug' => strtolower($warehouseCode),
            'type' => 'warehouse', 'is_active' => true, 'priority' => 1,
        ]);
        $product = Product::query()->create([
            'sku' => 'BY489A', 'name' => 'Lubricant',
            'product_type' => 'simple', 'is_active' => true,
        ]);
        ProductSkuAlias::query()->forceCreate([
            'product_id' => $product->id, 'alias_sku' => 'BY489A-Lubes',
            'match_key' => SkuMatchKey::compute('BY489A-Lubes'),
            'source_type' => 'woocommerce', 'is_active' => true,
        ]);
        $order = Order::query()->create([
            'erp_order_id' => '007-1113-c42380', 'internal_order_number' => 'INT-ALIAS-TEST',
            'source_type' => 'manual_other', 'order_kind' => 'customer',
            'order_status' => 'pending_shipment', 'order_date' => now()->toDateString(),
        ]);
        $order->address()->create([
            'recipient_name' => 'Synthetic Recipient', 'phone' => '5550001',
            'country' => 'US', 'province_state' => 'CA', 'city' => 'Test City',
            'postal_code' => '90210', 'address_line_1' => '100 Example St',
        ]);
        $order->items()->create([
            'product_id' => $product->id, 'sku' => 'BY489A-Lubes',
            'resolved_fulfillment_sku' => 'BY489A-Lubes',
            'product_summary' => 'Lubricant', 'quantity' => 1,
            'unit_price' => '5.00', 'line_revenue' => '5.00',
        ]);
        $order->shipment()->create([
            'warehouse_id' => $warehouse->id, 'shipment_status' => 'pending',
            'carrier' => 'USPS',
        ]);
        return $order->fresh(['shipment.warehouse', 'address', 'items']);
    }

    private function sheet(string $xlsx): string
    {
        $file = tempnam(sys_get_temp_dir(), 'byl-alias-xlsx-');
        self::assertNotFalse($file);
        file_put_contents($file, $xlsx);
        $zip = new ZipArchive;
        try {
            self::assertTrue($zip->open($file) === true);
            return (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        } finally {
            $zip->close();
            unlink($file);
        }
    }

    public function test_unverified_but_active_product_alias_exports_canonical_us_sm_draft_only(): void
    {
        $order = $this->order('US-SM');
        $xlsx = app(Wms64OutboundExporter::class)
            ->exportManualUsSmOrder($order->shipment, 'BYL-ALIAS-R123456');
        $sheet = $this->sheet($xlsx);
        self::assertStringContainsString('BY489A</t>', $sheet);
        self::assertStringNotContainsString('BY489A-Lubes</t>', $sheet);
        self::assertSame('BY489A-Lubes', $order->items->first()->fresh()->sku);
        self::assertSame('pending', $order->shipment->fresh()->shipment_status);
    }
    public function test_active_product_alias_exports_canonical_moreplus_file(): void
    {
        $order = $this->order('US-LA');
        $sheet = $this->sheet(app(MorePlusOutboundExporter::class)->export([$order->shipment]));
        self::assertStringContainsString('BY489A</t>', $sheet);
        self::assertStringNotContainsString('BY489A-Lubes</t>', $sheet);
    }

    public function test_inactive_alias_or_wrong_product_identity_blocks_conversion(): void
    {
        $order = $this->order('US-SM');
        $item = $order->items->first();
        $alias = ProductSkuAlias::query()->firstOrFail();
        $alias->forceFill(['is_active' => false])->save();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OUTBOUND_SKU_PRODUCT_IDENTITY_MISMATCH');
        app(OutboundOrderSkuResolver::class)->canonical('BY489A-Lubes', $item->product_id);
    }

    public function test_wrong_product_id_cannot_be_converted_into_canonical(): void
    {
        $order = $this->order('US-SM');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OUTBOUND_SKU_PRODUCT_IDENTITY_MISMATCH');
        app(OutboundOrderSkuResolver::class)->canonical('BY489A-Lubes', 987654);
    }
}
