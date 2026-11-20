<?php

namespace App\Modules\Products\Application;

use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductSkuAlias;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/** XLSX material-name staging only; preview never writes Product master data. */
final class MaterialNameImportService
{
    private const SHEETS = ['物料代码', '谦禾库存明细', '即时库存', '未生产订单'];

    public function preview(string $path): array
    {
        $spreadsheet = null;
        try {
            $reader = new Xlsx;
            $available = $reader->listWorksheetNames($path);
            $name = collect(self::SHEETS)->first(fn (string $candidate): bool => in_array($candidate, $available, true));
            if ($name === null) {
                throw new InvalidArgumentException('缺少可识别的物料工作表');
            }
            $reader->setReadDataOnly(true);
            $reader->setLoadSheetsOnly([$name]);
            $spreadsheet = $reader->load($path);
            $sheet = $spreadsheet->getSheetByName($name);
            if ($sheet === null) {
                throw new InvalidArgumentException('无法读取物料工作表');
            }
            [$headerRow, $codeColumn, $nameColumn] = $this->headers($sheet);
            $lastRow = $sheet->getHighestDataRow();
            if ($lastRow - $headerRow > 3000) {
                throw new InvalidArgumentException('最多接收 3000 行物料；请拆分文件');
            }
            $rows = [];
            $chars = 0;
            for ($rowNumber = $headerRow + 1; $rowNumber <= $lastRow; $rowNumber++) {
                $codeCell = $sheet->getCell([$codeColumn, $rowNumber]);
                $nameCell = $sheet->getCell([$nameColumn, $rowNumber]);
                $code = self::cellText($codeCell->getValue());
                $fullName = self::cellText($nameCell->getValue());
                if (trim($code) === '' && trim($fullName) === '') {
                    continue;
                }
                $chars += mb_strlen($code) + mb_strlen($fullName);
                if ($chars > 500000) {
                    throw new InvalidArgumentException('物料文本总量超出上限');
                }
                $invalid = trim($code) === '' || trim($fullName) === ''
                    || mb_strlen($code) > 128 || mb_strlen($fullName) > 10000
                    || $codeCell->getDataType() === DataType::TYPE_FORMULA
                    || $nameCell->getDataType() === DataType::TYPE_FORMULA;
                $rows[] = [
                    'sheet' => $name, 'source_row' => $rowNumber,
                    'material_code' => $code, 'material_full_name' => $fullName,
                    'status' => $invalid ? 'invalid' : 'unmatched',
                    'product_id' => null, 'product_sku' => null, 'current_name' => null,
                ];
            }
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable) {
            throw new InvalidArgumentException('XLSX 文件无法解析，请检查格式与内容');
        } finally {
            $spreadsheet?->disconnectWorksheets();
        }

        return $this->match($rows);
    }

    private static function cellText(mixed $value): string
    {
        return $value instanceof RichText ? $value->getPlainText()
            : (is_scalar($value) ? (string) $value : '');
    }

    /** @return array{int,int,int} */
    private function headers(Worksheet $sheet): array
    {
        $lastCol = min(50, Coordinate::columnIndexFromString($sheet->getHighestDataColumn()));
        for ($row = 1; $row <= min(15, $sheet->getHighestDataRow()); $row++) {
            $codeCol = null;
            $nameCol = null;
            for ($col = 1; $col <= $lastCol; $col++) {
                $value = self::headerKey(self::cellText($sheet->getCell([$col, $row])->getValue()));
                if (in_array($value, ['物料代码(旧系统)', '旧系统代码', '物料代码'], true)) {
                    $codeCol = $col;
                }
                if (in_array($value, ['物料名称', '产品名称'], true)) {
                    $nameCol = $col;
                }
            }
            if ($codeCol !== null && $nameCol !== null) {
                return [$row, $codeCol, $nameCol];
            }
        }
        throw new InvalidArgumentException('未找到「旧系统物料代码」及「物料名称」列');
    }

    private static function headerKey(string $value): string
    {
        return str_replace(['（', '）'], ['(', ')'],
            preg_replace('/\\s+/u', '', trim($value)) ?? '');
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function match(array $rows): array
    {
        $identity = [];
        foreach (Product::query()->get(['id', 'sku', 'material_full_name']) as $product) {
            $key = SkuMatchKey::compute((string) ($product->sku ?? ''));
            if ($key !== null) {
                $identity[$key][(int) $product->id] = $product;
            }
        }
        foreach (ProductSkuAlias::query()->with('product')
            ->where('is_active', true)->get() as $alias) {
            if ($alias->product === null) {
                continue;
            }
            $key = SkuMatchKey::compute((string) $alias->alias_sku);
            if ($key !== null) {
                $identity[$key][(int) $alias->product->id] = $alias->product;
            }
        }
        $keys = array_keys($identity);
        usort($keys, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($rows as &$row) {
            if ($row['status'] === 'invalid') {
                continue;
            }
            $nameKeys = [];
            $codeKeys = [];
            $name = ltrim($row['material_full_name']);
            $code = strtoupper(trim($row['material_code']));
            foreach ($keys as $key) {
                if (preg_match('/^'.preg_quote($key, '/').'(?![A-Za-z0-9])/iu', $name) === 1) {
                    $nameKeys[] = $key;
                }
                $upper = strtoupper($key);
                if (str_starts_with($code, '1'.$upper) || str_starts_with($code, $upper)) {
                    $codeKeys[] = $key;
                }
            }
            if ($codeKeys !== []) {
                $longest = max(array_map('strlen', $codeKeys));
                $codeKeys = array_values(array_filter($codeKeys, fn (string $key): bool => strlen($key) === $longest));
            }
            $possibleKeys = array_unique(array_merge($nameKeys, $codeKeys));
            $candidates = [];
            foreach ($possibleKeys as $key) {
                foreach ($identity[$key] as $id => $product) {
                    $candidates[$id] = $product;
                }
            }
            if (count($candidates) > 1) {
                $row['status'] = 'ambiguous_identity';

                continue;
            }
            if ($candidates === []) {
                continue;
            }
            $product = reset($candidates);
            $row['product_id'] = (int) $product->id;
            $row['product_sku'] = (string) $product->sku;
            $row['current_name'] = $product->material_full_name;
            $row['status'] = $product->material_full_name === $row['material_full_name']
                ? 'unchanged' : ($product->material_full_name === null || $product->material_full_name === ''
                    ? 'ready' : 'conflict_existing');
        }
        unset($row);
        // One SKU with multiple distinct source material names is never auto-applied.
        $variants = [];
        foreach ($rows as $row) {
            if ($row['product_id'] !== null && $row['status'] !== 'invalid') {
                $variants[$row['product_id']][hash('sha256', $row['material_full_name'])] = true;
            }
        }
        $seen = [];
        foreach ($rows as &$row) {
            $id = $row['product_id'];
            if ($id === null) {
                continue;
            }
            if (count($variants[$id]) > 1) {
                $row['status'] = 'ambiguous_variant';

                continue;
            }
            $key = $id.':'.hash('sha256', $row['material_full_name']);
            if (isset($seen[$key]) && $row['status'] === 'ready') {
                $row['status'] = 'duplicate';
            }
            $seen[$key] = true;
        }
        unset($row);

        return [
            'rows' => $rows,
            'total' => count($rows),
            'counts' => array_count_values(array_column($rows, 'status')),
        ];
    }
}
