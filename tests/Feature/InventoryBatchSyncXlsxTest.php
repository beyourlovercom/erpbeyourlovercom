<?php

namespace Tests\Feature;

use App\Modules\Inventory\Application\Sync\XlsxAdapter;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

final class InventoryBatchSyncXlsxTest extends TestCase
{
    public function test_xlsx_adapter_reads_first_worksheet_and_real_header_schema(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'inventory-sync-xlsx-');
        $this->assertNotFalse($path);
        $this->buildFixture($path);

        try {
            $file = new UploadedFile(
                $path,
                'fixture.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true,
            );
            $rows = (new XlsxAdapter())->parse($file);

            $this->assertCount(2, $rows);
            $this->assertSame('SF030C', $rows[0]->sku);
            $this->assertSame('sellable', $rows[0]->stockStatus);
            $this->assertSame(26, $rows[0]->quantity);
            $this->assertSame('2026-07-15', $rows[0]->putawayDate);
            $this->assertSame('德国仓(Hannover)', $rows[0]->rawWarehouseName);
            $this->assertSame('BAD-STATUS', $rows[1]->sku);
            $this->assertNull($rows[1]->stockStatus);
            $this->assertFalse(collect($rows)->contains(fn ($row) => $row->sku === 'SHOULD-NOT-READ'));
        } finally {
            @unlink($path);
        }
    }

    private function buildFixture(string $path): void
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);

        $zip->addFromString('xl/workbook.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets><sheet name="First" sheetId="1" r:id="rId1"/><sheet name="Second" sheetId="2" r:id="rId2"/></sheets>
</workbook>
XML);
        $zip->addFromString('xl/_rels/workbook.xml.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>
</Relationships>
XML);

        $headers = [
            'SKU', 'Product name/产品名称', 'Warehouse/仓库', 'Stock properties/库存属性',
            'Stock count date/库存统计日期', 'Putaway date/上架日期', 'Qty/数量',
            'Stock age/库龄（天）', 'Dimension/尺寸', 'Total volume总体积',
        ];
        $rows = [
            $headers,
            ['SF030C', 'Product', '德国仓(Hannover)', '正品', '2026-08-01', '2026-07-15', 26, '', '10x5', '2L'],
            ['BAD-STATUS', 'Product 2', '德国仓(Hannover)', '未知', '2026-08-01', '2026-07-16', 5, '', '', ''],
        ];
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->worksheetXml($rows));
        $zip->addFromString('xl/worksheets/sheet2.xml', $this->worksheetXml([['SKU', 'Qty/数量'], ['SHOULD-NOT-READ', 999]]));
        $zip->close();
    }

    private function worksheetXml(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $r => $values) {
            $rowNo = $r + 1;
            $xml .= '<row r="'.$rowNo.'">';
            foreach ($values as $c => $value) {
                $ref = $this->columnLetters($c + 1).$rowNo;
                if (is_int($value) || is_float($value)) {
                    $xml .= '<c r="'.$ref.'"><v>'.$value.'</v></c>';
                } else {
                    $safe = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                    $xml .= '<c r="'.$ref.'" t="inlineStr"><is><t>'.$safe.'</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        return $xml.'</sheetData></worksheet>';
    }

    private function columnLetters(int $number): string
    {
        $letters = '';
        while ($number > 0) {
            $number--;
            $letters = chr(65 + ($number % 26)).$letters;
            $number = intdiv($number, 26);
        }
        return $letters;
    }
}
