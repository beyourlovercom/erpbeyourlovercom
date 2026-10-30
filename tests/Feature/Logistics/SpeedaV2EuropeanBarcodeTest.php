<?php
namespace Tests\Feature\Logistics;

use App\Modules\Logistics\Application\Domestic\SpeedaOutboundExporter;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

final class SpeedaV2EuropeanBarcodeTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $sku, string $country, ?string $barcode, int $number): Order
    {
        $product = $barcode === null ? null : Product::create([
            'sku' => $sku, 'name' => 'Synthetic product', 'source_type' => 'manual',
            'product_type' => 'simple', 'barcode_69' => $barcode,
        ]);
        $order = Order::create([
            'erp_order_id' => '007-0930-c'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
            'internal_order_number' => 'B69-V2-'. $number, 'source_type' => 'manual',
            'order_kind' => 'customer', 'order_date' => now()->toDateString(),
            'order_status' => 'pending_shipment', 'currency' => 'USD',
        ]);
        $order->address()->create([
            'country' => $country, 'recipient_name' => 'Synthetic Customer', 'phone' => '000',
            'city' => 'Test City', 'postal_code' => '99999', 'address_line_1' => 'Test Road',
        ]);
        $order->items()->create([
            'sku' => $sku, 'product_summary' => 'Synthetic product', 'product_id' => $product?->id,
            'quantity' => 1, 'unit_price' => 1, 'line_revenue' => 1,
        ]);
        return $order->fresh(['address','items.product','externalReferences']);
    }
    private function rows(array $orders, string $carrier = '速达'): array
    {
        $bytes = app(SpeedaOutboundExporter::class)->export(collect($orders), $carrier);
        $path = tempnam(sys_get_temp_dir(), 'speeda-v2-');
        file_put_contents($path, $bytes);
        try {
            $workbook = IOFactory::load($path);
            $sheet = $workbook->getSheetByName('标准上传格式');
            $result = [];
            for ($i = 1; $i <= $sheet->getHighestDataRow(); $i++) {
                $result[$i] = $sheet->rangeToArray("A{$i}:AA{$i}", null, false, false)[0];
            }
            $result['highest'] = $sheet->getHighestDataColumn();
            $workbook->disconnectWorksheets();
            return $result;
        } finally { @unlink($path); }
    }

    public function test_europe_uses_exact_sku_and_preserves_leading_zero_barcode(): void
    {
        $rows = $this->rows([$this->order('TEST-SKU-FR', 'FR', '0697729139053', 901)]);
        $this->assertSame('AA', $rows['highest']);
        $this->assertSame(['商家产品识别码','制造商产品识别码','国际标准商品编码'], array_slice($rows[1], 24, 3));
        $this->assertSame(['TEST-SKU-FR','0697729139053',null], array_slice($rows[2], 24, 3));
    }

    public function test_non_europe_is_blank_and_new_columns_repeat_for_second_row(): void
    {
        $fr = $this->order('TEST-SKU-FR', 'FR', '6977291390537', 902);
        $us = $this->order('TEST-SKU-US', 'US', null, 903);
        $rows = $this->rows([$fr, $us]);
        $this->assertSame('AA', $rows['highest']);
        $this->assertSame(['TEST-SKU-FR','6977291390537',null], array_slice($rows[2], 24, 3));
        $this->assertSame([null,null,null], array_slice($rows[3], 24, 3));
    }
    public function test_missing_europe_barcode_isolated_without_blocking_other_orders(): void
    {
        $good = $this->order('GOOD-DE', 'DE', '6977291390537', 904);
        $bad = $this->order('MISSING-DE', 'DE', null, 905);
        $result = app(SpeedaOutboundExporter::class)->exportReady(collect([$good, $bad]));
        $this->assertSame(1, $result['exported_count']);
        $this->assertCount(1, $result['blocked']);
        $this->assertStringContainsString('69条形码', implode(' ', $result['blocked'][0]['reasons']));
    }

    public function test_multiple_distinct_europe_skus_are_blocked(): void
    {
        $mixed = $this->order('TEST-DE-A', 'DE', '6977291390537', 906);
        $other = Product::create(['sku'=>'TEST-DE-B','name'=>'Second synthetic','source_type'=>'manual',
            'product_type'=>'simple','barcode_69'=>'6977291390285']);
        $mixed->items()->create(['sku'=>'TEST-DE-B','product_id'=>$other->id,'product_summary'=>'Second synthetic',
            'quantity'=>1,'unit_price'=>1,'line_revenue'=>1]);
        $reasons = app(SpeedaOutboundExporter::class)->blockers($mixed->fresh(), '速达');
        $this->assertStringContainsString('多个SKU', implode(' ', $reasons));
    }

    public function test_two_lines_of_same_sku_keep_one_barcode_and_total_quantity(): void
    {
        $order = $this->order('SAME-DE', 'DE', '6977291390537', 908);
        $productId = $order->items->first()->product_id;
        $order->items()->create(['sku'=>'SAME-DE','product_id'=>$productId,
            'product_summary'=>'Second line same SKU', 'quantity'=>3,'unit_price'=>1,'line_revenue'=>3]);
        $rows = $this->rows([$order->fresh()]);
        $this->assertSame(['SAME-DE','6977291390537',null], array_slice($rows[2],24,3));
        $this->assertEquals(4, $rows[2][16], 'Q quantity totals both matching SKU lines');
    }

    public function test_mismatched_resolved_sku_is_not_given_an_unrelated_barcode(): void
    {
        $order = $this->order('REAL-DE', 'DE', '6977291390537', 909);
        $order->items()->update(['resolved_fulfillment_sku' => 'OTHER-DE']);
        $errors = app(SpeedaOutboundExporter::class)->blockers($order->fresh(), '速达');
        $this->assertStringContainsString('69条形码', implode(' ', $errors));
    }

    public function test_weship_keeps_24_column_contract_without_barcode_requirement(): void
    {
        $order = $this->order('WESHIP-DE', 'DE', null, 907);
        $this->assertSame([], app(SpeedaOutboundExporter::class)->blockers($order, '纬狮'));
        $rows = $this->rows([$order], '纬狮');
        $this->assertSame('X', $rows['highest']);
    }
}
