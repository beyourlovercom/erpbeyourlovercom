<?php

namespace App\Modules\Inventory\Experience\Controllers;

use App\Modules\Inventory\Application\WarehouseOverdueInventoryService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class WarehouseOverdueInventoryController
{
    public function __construct(private readonly WarehouseOverdueInventoryService $overdue) {}

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $report = $this->overdue->report();
        $rows = $this->matchingRows($report['rows'], $filters);
        $page = max(1, (int) $request->query('page', 1));
        $perPage = 50;
        $paged = new LengthAwarePaginator(
            array_slice($rows, ($page - 1) * $perPage, $perPage),
            count($rows), $perPage, $page,
            ['path' => $request->url(), 'query' => $request->except('page')],
        );

        return Inertia::render('Inventory/Overdue', [
            'asOf' => $report['as_of'],
            'cutoffDate' => $report['cutoff_date'],
            'summary' => $report['summary'],
            'warehouseUnits' => $report['warehouse_units'],
            'warehouses' => $report['warehouses'],
            'filters' => $filters,
            'filteredUnits' => array_sum(array_column($rows, 'quantity')),
            'filteredRows' => count($rows),
            'rows' => $paged->toArray(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $report = $this->overdue->report();
        $rows = $this->matchingRows($report['rows'], $filters);
        $filename = '007-超期库存-'.$report['as_of'].'.xlsx';

        return response()->streamDownload(
            function () use ($report, $rows): void {
                $book = $this->buildWorkbook($report, $rows);
                (new Xlsx($book))->save('php://output');
                $book->disconnectWorksheets();
            },
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'private, no-store'],
        );
    }

    /** @return array{warehouse:string,search:string} */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'warehouse' => ['nullable', 'string', 'max:32'],
            'search' => ['nullable', 'string', 'max:128'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        return [
            'warehouse' => trim((string) ($validated['warehouse'] ?? '')),
            'search' => trim((string) ($validated['search'] ?? '')),
        ];
    }

    /** @param list<array<string,mixed>> $rows
     * @param  array{warehouse:string,search:string}  $filters
     * @return list<array<string,mixed>>
     */
    private function matchingRows(array $rows, array $filters): array
    {
        return array_values(array_filter($rows, static function (array $row) use ($filters): bool {
            if ($filters['warehouse'] !== ''
                && strcasecmp($filters['warehouse'], (string) $row['warehouse_code']) !== 0) {
                return false;
            }
            $needle = mb_strtolower($filters['search']);
            if ($needle === '') {
                return true;
            }

            return str_contains(mb_strtolower(implode(' ', [
                $row['sku'], $row['canonical_sku'], $row['product_name'], $row['warehouse_name'],
            ])), $needle);
        }));
    }

    /** @param array<string,mixed> $report
     * @param  list<array<string,mixed>>  $rows
     */
    private function buildWorkbook(array $report, array $rows): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->getProperties()->setTitle('007 超期库存')
            ->setDescription('BYL ERP 真实在库库存及入库日期，满90日的批次清单');
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('超期库存');

        // The first seven columns exactly match the supplied 007 Excel sample.
        $headers = ['仓别', '组别', '责任人', '入库时间', 'FNSKU', '物料名称', '数量',
            '在库天数', '超期天数', 'ERP SKU', '批次号', '库存状态', '数据来源', '核对状态'];
        foreach ($headers as $index => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).'1', $header);
        }

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            // Ownership/group fields are not stored in ERP. Do not invent names.
            $values = [
                $row['warehouse_name'], '', '', $row['putaway_date'], $row['sku'],
                $row['product_name'], $row['quantity'], $row['age_days'],
                $row['overdue_days'], $row['canonical_sku'], $row['lot_id'] ?? '',
                $row['stock_status'] === 'sellable' ? '正品' : '次品',
                $row['source'] === 'dated_lot' ? 'ERP入库批次' : 'ERP库存入库日期',
                $row['quality'],
            ];
            foreach ($values as $column => $value) {
                $coordinate = Coordinate::stringFromColumnIndex($column + 1).$line;
                if (in_array($column, [6, 7, 8], true)) {
                    $sheet->setCellValue($coordinate, (int) $value);
                } else {
                    // Explicit text prevents spreadsheet formula injection by
                    // externally sourced SKU or product names.
                    $sheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING);
                }
            }
        }

        $last = max(2, count($rows) + 1);
        $sheet->getStyle('A1:N1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '183847']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(26);
        foreach (['A' => 20, 'B' => 12, 'C' => 12, 'D' => 16,
            'E' => 19, 'F' => 42, 'G' => 11, 'H' => 12, 'I' => 12,
            'J' => 19, 'K' => 12, 'L' => 12, 'M' => 20, 'N' => 38] as $letter => $width) {
            $sheet->getColumnDimension($letter)->setWidth($width);
        }
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:N'.$last);
        $sheet->getStyle('G2:I'.$last)->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle('A1:N'.$last)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        $notes = $book->createSheet();
        $notes->setTitle('统计说明');
        $lines = [
            ['统计日期', $report['as_of']],
            ['业务日期时区', 'UTC+8（Asia/Taipei）；入库日期以日为单位，不取同步时间'],
            ['判定条件', '入库满 90 个自然日（包括第 90 天），入库日期不晚于 '.$report['cutoff_date']],
            ['库存来源', 'ERP 当前在库数量 + 实际入库批次，不读取已发货的历史库存作为现货'],
            ['批次口径', '同 SKU 不同日期 / 批次分行，历史批次数量超过实时库存时做保守封顶'],
            ['未验证库存', $report['summary']['unknown_age_units'].' 件缺乏可核验日期，未计入超期'],
            ['批次差异', $report['summary']['lot_mismatch_skus'].' 个 SKU 的批次数量与 ERP 当前库存不一致，需要人工复核'],
            ['组别/责任人', 'ERP 当前没有对应稳定字段；保留空列，不从上传的示例复制负责人'],
            ['费用', '超期明细不直接计算或修改 P9 库龄仓储费；账务规则独立'],
            ['导出数量', array_sum(array_column($rows, 'quantity')).' 件 / '.count($rows).' 条批次记录'],
        ];
        foreach ($lines as $i => $line) {
            $notes->setCellValue('A'.($i + 1), $line[0]);
            $notes->setCellValue('B'.($i + 1), $line[1]);
        }
        $notes->getColumnDimension('A')->setWidth(19);
        $notes->getColumnDimension('B')->setWidth(93);
        $notes->getStyle('A1:A'.count($lines))->getFont()->setBold(true);

        return $book;
    }
}
