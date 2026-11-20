<?php

namespace App\Modules\Products\Application;

use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Read-only evidence extraction. No ERP product matching or cost database writes occur here.
 * Callers must select an actual product ID and pass rows through confirmed-cost preflight.
 */
final class MonthlyInventoryCostWorkbookReader
{
    private const MAX_BYTES = 20 * 1024 * 1024;

    private const MAX_ROWS_PER_SHEET = 50_000;

    /**
     * @return array{rows:list<array<string,mixed>>,excluded:list<array<string,mixed>>,errors:list<string>,summary:array<string,int>}
     */
    public function read(string $path, int $year, ?int $csvMonth = null, ?string $sourceFilename = null): array
    {
        if (! is_file($path) || ! is_readable($path) || filesize($path) > self::MAX_BYTES) {
            throw new InvalidArgumentException('inventory_cost_file_unreadable_or_too_large');
        }
        if ($year < 2000 || $year > 2100 || ($csvMonth !== null && ($csvMonth < 1 || $csvMonth > 12))) {
            throw new InvalidArgumentException('inventory_cost_period_invalid');
        }

        $extension = strtolower(pathinfo($sourceFilename ?? $path, PATHINFO_EXTENSION));
        if (! in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            throw new InvalidArgumentException('inventory_cost_file_format_unsupported');
        }

        if ($extension === 'csv') {
            $reader = new Csv;
            $reader->setInputEncoding('UTF-8');
            $reader->setReadDataOnly(true);
            $workbook = $reader->load($path);
        } else {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $workbook = $reader->load($path);
        }

        $rows = [];
        $excluded = [];
        $errors = [];
        try {
            foreach ($workbook->getWorksheetIterator() as $sheet) {
                $sheetName = trim($sheet->getTitle());
                $month = $extension === 'csv' ? $csvMonth : $this->monthFromSheet($sheetName);
                if ($month === null) {
                    // Non-month inventory and system-outbound tabs must never create product costs.
                    continue;
                }

                $highestRow = $sheet->getHighestDataRow();
                if ($highestRow > self::MAX_ROWS_PER_SHEET) {
                    $errors[] = $sheetName.':too_many_rows';

                    continue;
                }
                $header = $this->detectHeader($sheet);
                if ($header === null) {
                    $errors[] = $sheetName.':required_name_and_original_cost_headers_missing';

                    continue;
                }

                $period = sprintf('%04d-%02d-01', $year, $month);
                for ($rowNumber = $header['row'] + 1; $rowNumber <= $highestRow; $rowNumber++) {
                    $name = $this->cellText($sheet, $header['name'], $rowNumber);
                    $factoryCode = $header['code'] === null ? '' : $this->cellText($sheet, $header['code'], $rowNumber);
                    $rawCost = $this->cellText($sheet, $header['cost'], $rowNumber);
                    $resolvedCost = $rawCost;
                    $costValueSource = 'literal';
                    if (str_starts_with($rawCost, '=')) {
                        // Never evaluate arbitrary workbook formulas. Read only their saved Excel value.
                        $cached = $sheet->getCell([$header['cost'], $rowNumber])->getOldCalculatedValue();
                        $resolvedCost = is_scalar($cached) ? trim((string) $cached) : '';
                        $costValueSource = 'xlsx_cached_formula';
                    }
                    if ($name === '' && $rawCost === '' && $factoryCode === '') {
                        continue;
                    }
                    $source = [
                        'source_sheet' => $sheetName,
                        'source_row' => $rowNumber,
                        'effective_from' => $period,
                        'product_name' => $name,
                        'factory_code' => $factoryCode,
                        'raw_unit_cost' => $rawCost,
                        'resolved_unit_cost_raw' => $resolvedCost,
                        'cost_value_source' => $costValueSource,
                        'currency' => 'CNY',
                    ];
                    if ($name === '' || preg_match('/^(合计|总计|小计|序号)(\\s|$)/u', $name)) {
                        $excluded[] = $source + ['reason' => 'not_product_line'];

                        continue;
                    }
                    if (preg_match('/^(外采|冲减|暂估)/u', $name)) {
                        $excluded[] = $source + ['reason' => 'procurement_or_adjustment_line'];

                        continue;
                    }
                    if ($header['closing'] !== null
                        && $this->cellText($sheet, $header['closing'], $rowNumber) === ''
                        && ! preg_match('/^(?:1[A-Z0-9]{7,}|2200[A-Z0-9]{6,})$/D', strtoupper($factoryCode))) {
                        // Factory-coded products may legitimately have a blank closing quantity.
                        // Rows without both closing stock and a stable factory product code
                        // are often order-level evidence; preserve them for manual review.
                        $excluded[] = $source + ['reason' => 'closing_inventory_quantity_missing_requires_review'];

                        continue;
                    }

                    $unitCost = $this->normalizedCost($resolvedCost);
                    if ($unitCost === null) {
                        $excluded[] = $source + ['reason' => $costValueSource === 'xlsx_cached_formula' ? 'formula_cached_cost_missing_or_invalid' : 'unit_cost_missing_or_invalid'];

                        continue;
                    }

                    $sku = InventoryCostSkuExtractor::extract($name, $factoryCode);
                    $rows[] = $source + [
                        'unit_cost' => $unitCost,
                        'base_sku' => $sku['base_sku'],
                        'extraction_status' => $sku['status'],
                        'sku_evidence' => $sku['evidence'],
                        'sku_candidates' => $sku['candidates'],
                        'source_variant_key' => $period.'|'.$sheetName.'|'.$rowNumber,
                        'status' => match ($sku['status']) {
                            'extracted' => 'needs_erp_match',
                            'needs_confirmation' => 'needs_confirmation',
                            default => 'needs_review',
                        },
                    ];
                }
            }

            // A candidate with two actual source prices in the same month is a
            // human decision, never "last row wins".
            $seen = [];
            foreach ($rows as $i => $row) {
                if ($row['base_sku'] !== null) {
                    $seen[$row['effective_from'].'|'.$row['base_sku']][$row['unit_cost']][] = $i;
                }
            }
            foreach ($seen as $costs) {
                if (count($costs) < 2) {
                    continue;
                }
                foreach ($costs as $indexes) {
                    foreach ($indexes as $i) {
                        $rows[$i]['status'] = 'conflict';
                    }
                }
            }
        } finally {
            $workbook->disconnectWorksheets();
        }

        return [
            'rows' => $rows,
            'excluded' => $excluded,
            'errors' => $errors,
            'summary' => [
                'cost_rows' => count($rows),
                'excluded_rows' => count($excluded),
                'conflict_rows' => count(array_filter($rows, static fn (array $row) => $row['status'] === 'conflict')),
                'needs_review_rows' => count(array_filter($rows, static fn (array $row) => in_array($row['status'], ['needs_review', 'needs_confirmation'], true))),
            ],
        ];
    }

    private function monthFromSheet(string $title): ?int
    {
        if (! preg_match('/(?<![0-9])([0-9]{1,2})月进销存/u', $title, $match)) {
            return null;
        }
        $month = (int) $match[1];

        return $month >= 1 && $month <= 12 ? $month : null;
    }

    /** @return array{row:int,name:int,cost:int,code:?int,closing:?int}|null */
    private function detectHeader(Worksheet $sheet): ?array
    {
        $columnLimit = min(30, Coordinate::columnIndexFromString($sheet->getHighestDataColumn()));
        for ($row = 1; $row <= min(15, $sheet->getHighestDataRow()); $row++) {
            $name = null;
            $code = null;
            $closing = null;
            $cost = null;
            $costPriority = 0;
            for ($col = 1; $col <= $columnLimit; $col++) {
                $header = preg_replace('/\\s+/u', '', $this->cellText($sheet, $col, $row));
                if (in_array($header, ['名称', '商名名称', '商品名称', '商品名', '品名', '产品名称'], true)) {
                    $name = $col;
                } elseif (in_array($header, ['产品长代码', '长代码', '产品代码', '商品编码', '工厂代码'], true)) {
                    $code = $col;
                } elseif ($header === '期末数量') {
                    $closing = $col;
                }
                $priority = match ($header) {
                    '原成本单价', '原成本价' => 3,
                    '成本价', '成本单价', '单位成本', '采购成本单价' => 2,
                    default => 0,
                };
                if ($priority > $costPriority) {
                    $cost = $col;
                    $costPriority = $priority;
                }
            }
            if ($name !== null && $cost !== null) {
                return ['row' => $row, 'name' => $name, 'cost' => $cost, 'code' => $code, 'closing' => $closing];
            }
        }

        return null;
    }

    private function cellText(Worksheet $sheet, int $column, int $row): string
    {
        $value = $sheet->getCell([$column, $row])->getValue();
        if ($value === null || is_object($value) || is_array($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private function normalizedCost(string $raw): ?string
    {
        $candidate = str_replace([',', '￥', '¥', ' '], '', trim($raw));
        if (! preg_match('/^[0-9]{1,8}(?:\\.[0-9]{1,16})?$/D', $candidate)) {
            return null;
        }
        if (bccomp($candidate, '0', 12) <= 0) {
            return null;
        }

        return bcadd($candidate, '0', 12);
    }
}
