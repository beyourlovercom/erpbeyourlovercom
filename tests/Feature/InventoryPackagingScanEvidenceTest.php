<?php

namespace Tests\Feature;

use App\Modules\Inventory\Application\PackagingScan\PackagingScanEvidencePreviewService;
use App\Modules\Inventory\Application\PackagingScan\PackagingScanXlsxReader;
use App\Modules\Inventory\Infrastructure\Models\PackagingMeasurementRecord;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

final class InventoryPackagingScanEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) @unlink($path);
        parent::tearDown();
    }

    public function test_reader_accepts_bilingual_logistics_scan_headers(): void
    {
        $path = $this->xlsx([
            ['客户参考单号 Customer Reference Number','运单号 Tracking Number','预报重量 Forecast Weight','签入重量 Inbound Weight','体积重量 Volume Weight','计费重量 Billing Weight','重量差异 Weight Difference','长 length','宽 width','高 height'],
            ['ORDER-1','TRACK-1','1.300','1.360','0.001','1.360','0.060','20','10','8'],
        ]);
        $rows = app(PackagingScanXlsxReader::class)->parsePath($path);
        $this->assertCount(1, $rows);
        $this->assertSame('ORDER-1', $rows[0]['order_reference']);
        $this->assertSame('1.360', $rows[0]['inbound_weight_kg']);
        $this->assertSame('20', $rows[0]['length_cm']);
    }

    public function test_preview_only_promotes_single_sku_single_unit_with_real_dimensions(): void
    {
        $p1 = $this->product('SKU-ONE');
        $p2 = $this->product('SKU-TWO');
        $single = $this->order('ORDER-1');
        $single->items()->create(['product_id'=>$p1->id,'product_summary'=>'One','sku'=>$p1->sku,'quantity'=>1,'unit_price'=>10,'line_revenue'=>10]);
        $multiUnit = $this->order('ORDER-2');
        $multiUnit->items()->create(['product_id'=>$p1->id,'product_summary'=>'One','sku'=>$p1->sku,'quantity'=>2,'unit_price'=>10,'line_revenue'=>20]);
        $multiSku = $this->order('ORDER-3');
        $multiSku->items()->create(['product_id'=>$p1->id,'product_summary'=>'One','sku'=>$p1->sku,'quantity'=>1,'unit_price'=>10,'line_revenue'=>10]);
        $multiSku->items()->create(['product_id'=>$p2->id,'product_summary'=>'Two','sku'=>$p2->sku,'quantity'=>1,'unit_price'=>10,'line_revenue'=>10]);

        $rows = [
            $this->row(2, 'ORDER-1', 1.36, 20, 10, 8),
            $this->row(3, 'ORDER-1', 1.36, 1, 1, 1),
            $this->row(4, 'ORDER-2', 2.70, 30, 20, 12),
            $this->row(5, 'ORDER-3', 1.80, 25, 18, 10),
            $this->row(6, 'UNKNOWN', 1.10, 20, 10, 8),
        ];
        $result = app(PackagingScanEvidencePreviewService::class)->preview($rows);
        $this->assertSame(5, $result['summary']['rows']);
        $this->assertSame(4, $result['summary']['matched_orders']);
        $this->assertSame(1, $result['summary']['ready_single_unit_evidence']);
        $this->assertSame(1, $result['summary']['placeholder_dimensions']);
        $this->assertSame(1, $result['summary']['multi_unit']);
        $this->assertSame(1, $result['summary']['multi_sku']);
        $this->assertSame(1, $result['summary']['unmatched_orders']);
        $this->assertTrue($result['rows'][0]['can_be_product_evidence']);
        $this->assertSame('SKU-ONE', $result['rows'][0]['product']['sku']);
        $this->assertSame('placeholder_dimensions', $result['rows'][1]['evidence_status']);
        $this->assertSame('single_sku_multi_unit', $result['rows'][2]['classification']);
        $this->assertSame('multi_sku', $result['rows'][3]['classification']);
        $this->assertDatabaseCount('packaging_measurement_records', 0);
    }

    private function product(string $sku): Product
    {
        return Product::create(['source_type'=>'manual','sku'=>$sku,'name'=>'Fixture '.$sku,'is_active'=>true]);
    }

    private function order(string $number): Order
    {
        return Order::create(['internal_order_number'=>'ERP-'.$number,'source_type'=>'woocommerce','order_kind'=>'customer','external_order_number'=>$number,'order_date'=>'2026-09-08','order_status'=>'pending_shipment']);
    }

    private function row(int $row, string $order, float $weight, float $l, float $w, float $h): array
    {
        return ['source_row_number'=>$row,'order_reference'=>$order,'tracking_number'=>'TRACK-'.$row,'inbound_weight_kg'=>(string)$weight,'length_cm'=>(string)$l,'width_cm'=>(string)$w,'height_cm'=>(string)$h];
    }

    /** @param list<list<string>> $rows */
    private function xlsx(array $rows): string
    {
        $path = tempnam(storage_path(), 'packaging-scan-');
        self::assertIsString($path); $this->paths[] = $path;
        $zip = new ZipArchive(); $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $sheet = '';
        foreach ($rows as $r => $row) {
            $sheet .= '<row r="'.($r+1).'">';
            foreach ($row as $c => $value) {
                $letters = ''; $n = $c + 1;
                while ($n > 0) { $n--; $letters = chr(65 + ($n % 26)).$letters; $n = intdiv($n, 26); }
                $sheet .= '<c r="'.$letters.($r+1).'" t="inlineStr"><is><t>'.htmlspecialchars($value, ENT_XML1).'</t></is></c>';
            }
            $sheet .= '</row>';
        }
        $zip->addFromString('[Content_Types].xml','<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/xl/workbook.xml" ContentType="x"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="x"/></Types>');
        $zip->addFromString('xl/workbook.xml','<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="worksheet"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml','<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheet.'</sheetData></worksheet>');
        $zip->close(); return $path;
    }
}
