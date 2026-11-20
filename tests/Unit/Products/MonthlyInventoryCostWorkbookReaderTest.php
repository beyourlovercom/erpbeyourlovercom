<?php

namespace Tests\Unit\Products;

use App\Modules\Products\Application\MonthlyInventoryCostWorkbookReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

final class MonthlyInventoryCostWorkbookReaderTest extends TestCase
{
    public function test_reads_all_months_original_cost_columns_and_marks_price_conflicts(): void
    {
        $book = new Spreadsheet;
        $feb = $book->getActiveSheet();
        $feb->setTitle('2月进销存');
        $feb->fromArray([
            ['2026年2月进销存明细'],
            ['序号', '组别', '商名名称', '销售结算价', '成本价'],
            [1, '007', 'VA617A（国内新版）', 99, 28],
        ], null, 'A1');

        $mar = $book->createSheet();
        $mar->setTitle('3月进销存');
        $mar->fromArray([
            ['名称', '期初数量', '期初数值', '入库数量', '期末数量', '成本单价', '原成本单价'],
            ['BX033B蓝色', 1, 1, 1, 1, 90, 18],
        ], null, 'A1');

        $apr = $book->createSheet();
        $apr->setTitle('4月进销存 ');
        $apr->fromArray([
            ['名称', '产品长代码', '期初数量', '期初数值', '入库数量', '期末数量', '成本单价', '原成本单价'],
            ['BEYOURLOVER 35毫升软管润滑油', '1BY489A5C0100', 1, 1, 1, 1, 1.32, 1.2],
        ], null, 'A1');

        $oct = $book->createSheet();
        $oct->setTitle('10月进销存  ');
        $oct->fromArray([
            ['名称', '产品长代码', '期初数量', '期初数值', '入库数量', '期末数量', '成本单价', '原成本单价'],
            ['VA617A（国内新版）', '', 1, 1, 1, 1, 40, 28],
            ['VA617A 包装更新款', '', 1, 1, 1, 1, 40, 29],
            ['无编码包装袋', '', 1, 1, 1, 1, 0, ''],
        ], null, 'A1');

        $notCosts = $book->createSheet();
        $notCosts->setTitle('系统出库');
        $notCosts->fromArray([['名称', '原成本单价'], ['VA617A', 1000]], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'inventory-cost-').'.xlsx';
        try {
            (new Xlsx($book))->save($path);
            $result = (new MonthlyInventoryCostWorkbookReader)->read($path, 2026);
            $this->assertSame([], $result['errors']);
            $this->assertSame(5, $result['summary']['cost_rows']);
            $this->assertSame(1, $result['summary']['excluded_rows']);
            $this->assertSame(2, $result['summary']['conflict_rows']);

            $this->assertSame('VA617A', $result['rows'][0]['base_sku']);
            $this->assertSame('2026-02-01', $result['rows'][0]['effective_from']);
            $this->assertSame('28.000000000000', $result['rows'][0]['unit_cost']);
            $this->assertSame('18.000000000000', $result['rows'][1]['unit_cost']);
            $this->assertSame('BY489A', $result['rows'][2]['base_sku']);
            $this->assertSame('needs_confirmation', $result['rows'][2]['status']);
            $this->assertSame('conflict', $result['rows'][3]['status']);
            $this->assertSame('conflict', $result['rows'][4]['status']);
            $this->assertSame('unit_cost_missing_or_invalid', $result['excluded'][0]['reason']);
        } finally {
            $book->disconnectWorksheets();
            @unlink($path);
        }
    }

    public function test_cached_excel_formula_and_transaction_row_are_separated(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('10月进销存 ');
        $sheet->fromArray([
            ['名称', '产品长代码', '期初数量', '期初数值', '入库数量', '期末数量', '成本单价', '原成本单价'],
            ['BA561A双头按摩棒', '1BA561A5C0150', 1, 1, 1, 1, 68, null],
            ['A1 交易型采购单', 'C40295W', null, null, 1, null, 99, 99],
            ['S106E 抱球棒', '1VS106E04C120', null, null, 1, null, 85, 73.4589],
        ], null, 'A1');
        $sheet->setCellValue('H2', '=58.773');
        $path = tempnam(sys_get_temp_dir(), 'inventory-cost-formula-').'.xlsx';
        try {
            (new Xlsx($book))->save($path);
            $result = (new MonthlyInventoryCostWorkbookReader)->read($path, 2026);
            $this->assertSame(2, $result['summary']['cost_rows']);
            $this->assertSame(1, $result['summary']['excluded_rows']);
            $this->assertSame('closing_inventory_quantity_missing_requires_review', $result['excluded'][0]['reason']);
            $this->assertSame('xlsx_cached_formula', $result['rows'][0]['cost_value_source']);
            $this->assertSame('=58.773', $result['rows'][0]['raw_unit_cost']);
            $this->assertSame('58.773000000000', $result['rows'][0]['unit_cost']);
            $this->assertSame('S106E', $result['rows'][1]['base_sku']);
            $this->assertSame('ambiguous', $result['rows'][1]['extraction_status']);
        } finally {
            $book->disconnectWorksheets();
            @unlink($path);
        }
    }

    public function test_missing_period_and_unsupported_format_fail_closed(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'inventory-cost-');
        try {
            $this->expectException(\InvalidArgumentException::class);
            (new MonthlyInventoryCostWorkbookReader)->read($file, 2026);
        } finally {
            @unlink($file);
        }
    }
}
