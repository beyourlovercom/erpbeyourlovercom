<?php

namespace App\Modules\Logistics\Application;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/** Parse bounded known source layouts, retaining original workbook and marking every row unreviewed. */
final class LogisticsRateWorkbookParser
{
    public function parse(string $path): array
    {
        $format = IOFactory::identify($path, ['Xlsx', 'Xls']);
        $reader = IOFactory::createReader($format);
        $reader->setReadDataOnly(true);
        $names = $reader->listWorksheetNames($path);
        if (count($names) > 60) {
            throw new RuntimeException('工作表过多');
        }
        $source = match (true) {
            in_array('USPS尾程', $names, true) && in_array('GOFO尾程', $names, true) => 'speeda_us',
            in_array('DE-DHL （本地）', $names, true) && in_array('库内基础服务费用', $names, true) => 'tianyun_de',
            in_array('SD经济挂号-带电', $names, true) => 'speeda_sd',
            $format === 'Xls' && in_array('Sheet1', $names, true) => 'territory_evidence',
            default => throw new RuntimeException('无法识别报价表结构，未导入'),
        };
        $selected = match ($source) {
            'speeda_us' => ['USPS尾程', 'GOFO尾程', '美东仓USPS分区表', '美西仓USPS分区表', 'GOFO美东分区表'],
            'tianyun_de' => ['DE-DHL （本地）', '库内基础服务费用'],
            'speeda_sd' => ['SD经济挂号-带电'],
            default => [],
        };
        $out = ['book_key' => $source, 'sheets' => $names, 'parsed_sheets' => [], 'components' => [], 'zones' => [], 'warnings' => []];
        if ($selected === []) {
            $out['warnings'][] = '地区例外资料，仅保留原始文件；不能自动生成运费或当前税务规则。';

            return $out;
        }
        $selected = array_values(array_intersect($selected, $names));
        // Explicitly bounded rows; postal sheets with larger matrices remain review-only.
        $reader->setLoadSheetsOnly($selected);
        $reader->setReadFilter(new class implements IReadFilter
        {
            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                $max = str_contains($worksheetName, '分区表') ? 12000 : 400;

                return $row <= $max && preg_match('/^[A-L]$/D', $columnAddress) === 1;
            }
        });
        $book = $reader->load($path);
        try {
            foreach ($selected as $name) {
                $sheet = $book->getSheetByName($name);
                if (! $sheet) {
                    continue;
                }
                if ($source === 'speeda_us') {
                    if ($name === 'USPS尾程') {
                        $this->usMatrix($sheet, $out, false);
                    } elseif ($name === 'GOFO尾程') {
                        $this->usMatrix($sheet, $out, true);
                    } else {
                        $this->zones($sheet, $out);
                    }
                } elseif ($source === 'tianyun_de') {
                    $this->de($sheet, $out);
                } else {
                    $this->sd($sheet, $out);
                }
                $out['parsed_sheets'][] = $name;
            }
        } finally {
            $book->disconnectWorksheets();
        }
        $out['warnings'][] = '全部解析行默认待审核。未解析工作表及原始附加费/禁限运条款须逐项确认，禁止自动启用。';
        if ($source === 'speeda_us') {
            $out['warnings'][] = 'US-NJ/US-LA 是供应商报价范围，不代表 ERP 纽约仓或 MorePlus 合同已核实。USPS 为 Ground Advantage；邮编特殊标记、附加费待审核。';
        }
        if ($source === 'tianyun_de') {
            $out['warnings'][] = '仅德国本地基础运费、操作费；国际票价存在计费冲突，未自动解析。';
        }
        if ($source === 'speeda_sd') {
            $out['warnings'][] = '只解析 SD经济挂号-带电已识别国家基础价。新表体积规则与历史规则冲突，且部分国家品类/邮编限制必须审核。';
        }

        return $out;
    }

    private function value(Worksheet $s, string $cell): ?string
    {
        $v = $s->getCell($cell)->getValue();
        if (! is_scalar($v)) {
            return null;
        }
        $v = trim((string) $v);

        return $v === '' || str_starts_with($v, '=') ? null : $v;
    }

    private function band(int $seq, ?string $min, string $max, string $fixed, ?string $per, ?string $extra, string $cells, array $raw = [], bool $minInclusive = false): array
    {
        return ['sequence' => $seq, 'min_weight' => $min, 'max_weight' => $max, 'min_inclusive' => $minInclusive,
            'max_inclusive' => true, 'fixed_amount' => $fixed, 'per_weight_amount' => $per, 'per_extra_item_amount' => $extra,
            'source_cells' => $cells, 'raw_values' => $raw];
    }

    private function component(array &$out, string $key, string $warehouse, string $carrier, string $service, string $country, ?string $zone, string $type, string $currency, string $unit, string $mode, array $rule, string $sheet, string $cells, array $bands): void
    {
        if ($bands === []) {
            return;
        }
        $out['components'][] = ['component_key' => $key, 'warehouse_code' => $warehouse, 'carrier' => $carrier, 'service' => $service,
            'destination_country' => $country, 'destination_zone' => $zone, 'component_type' => $type, 'currency' => $currency,
            'weight_unit' => $unit, 'pricing_mode' => $mode, 'rule_spec' => ['coverage_complete' => false, ...$rule],
            'source_sheet' => $sheet, 'source_cells' => $cells, 'review_status' => 'needs_review', 'bands' => $bands];
    }

    private function usMatrix(Worksheet $s, array &$out, bool $gofo): void
    {
        $header = $this->value($s, 'A1') ?? '';
        if ($gofo ? ! str_contains($header, 'GOFO') : ! str_contains($header, 'Ground Advantage')) {
            throw new RuntimeException('美国尾程报价标题不匹配');
        }
        $first = $gofo ? 3 : 6;
        $end = $gofo ? 38 : 91;
        $carrier = $gofo ? 'GOFO' : 'USPS';
        $service = $gofo ? 'express' : 'ground_advantage';
        // Native ounce bands, including pounds converted to exact ounces; do not use rounded KG column.
        foreach ($gofo ? ['US-NJ'] : ['US-NJ', 'US-LA'] as $warehouse) {
            for ($zone = 1; $zone <= ($gofo ? 8 : 9); $zone++) {
                $col = chr(ord($gofo ? 'B' : 'C') + $zone - 1);
                $bands = [];
                $previous = '0';
                for ($r = $first; $r <= $end; $r++) {
                    $raw = $this->value($s, 'A'.$r);
                    $unit = $gofo ? null : $this->value($s, 'B'.$r);
                    if ($gofo && $raw && preg_match('/^(\d+(?:\.\d+)?)\s*(OZ|LB)$/iD', $raw, $m)) {
                        $raw = $m[1];
                        $unit = $m[2];
                    }
                    $price = $this->value($s, $col.$r);
                    if (! is_numeric($raw) || ! in_array(strtolower((string) $unit), ['oz', 'lb'], true) || ! is_numeric($price) || (float) $price < 0) {
                        continue;
                    }
                    $native = (float) $raw * (strtolower($unit) === 'lb' ? 16 : 1);
                    if ($native <= (float) $previous) {
                        throw new RuntimeException('美国重量档位重复或逆序');
                    }
                    $max = rtrim(rtrim(sprintf('%.6f', $native), '0'), '.');
                    $bands[] = $this->band(count($bands) + 1, $previous, $max, $price, null, null, 'A'.$r.':'.$col.$r, ['native_weight' => $raw, 'native_unit' => $unit, 'native_price' => $price]);
                    $previous = $max;
                }
                $rule = $gofo ? ['basis' => 'max', 'dim_divisor' => 6000, 'volume_unit' => 'cm3_kg', 'max_actual_kg' => 9, 'max_dimensions_cm' => [60, 50, 40]]
                    : ['basis' => 'conditional_max', 'dim_divisor' => 166, 'volume_unit' => 'in3_lb', 'volume_threshold' => 1728, 'max_actual_kg' => 31.75, 'max_girth_cm' => 330, 'excluded_zip_prefixes' => ['006', '007', '008', '009', '090', '091', '092', '093', '094', '095', '096', '097', '098', '099', '340', '962', '963', '964', '965', '966', '967', '968', '969', '995', '996', '997', '998', '999']];
                $this->component($out, strtolower($warehouse).'.'.strtolower($carrier).'.zone'.$zone, $warehouse, $carrier, $service, 'US', (string) $zone, 'transport', 'USD', 'oz', 'fixed_band', $rule, $s->getTitle(), 'A'.$first.':'.$col.$end, $bands);
            }
        }
    }

    private function zones(Worksheet $s, array &$out): void
    {
        $name = $s->getTitle();
        $gofo = str_contains($name, 'GOFO');
        $warehouse = str_contains($name, '美西') ? 'US-LA' : 'US-NJ';
        $carrier = $gofo ? 'GOFO' : 'USPS';
        $service = $gofo ? 'express' : 'ground_advantage';
        $len = $gofo ? 5 : 3;
        $limit = min($s->getHighestDataRow(), 12000);
        for ($r = 2; $r <= $limit; $r++) {
            $pairs = $gofo ? [['D', 'A', 'E']] : [['A', 'B', null], ['C', 'D', null], ['E', 'F', null], ['G', 'H', null]];
            foreach ($pairs as [$p,$z,$e]) {
                $raw = $this->value($s, $p.$r);
                $label = $this->value($s, $z.$r);
                if ($raw === null || $label === null) {
                    continue;
                }
                if ($e) {
                    if ($this->value($s, 'B'.$r) !== 'US') {
                        continue;
                    }
                    $end = $this->value($s, $e.$r);
                    if (! ctype_digit($raw) || $end === null || ! ctype_digit($end)) {
                        continue;
                    }
                    $start = str_pad($raw, $len, '0', STR_PAD_LEFT);
                    $end = str_pad($end, $len, '0', STR_PAD_LEFT);
                } else {
                    if (! preg_match('/^(\d{1,3})(?:\s*[-–—]+\s*(\d{1,3}))?$/uD', $raw, $m)) {
                        continue;
                    }
                    $start = str_pad($m[1], $len, '0', STR_PAD_LEFT);
                    $end = str_pad($m[2] ?? $m[1], $len, '0', STR_PAD_LEFT);
                }
                if (strlen($start) !== $len || strlen($end) !== $len || strcmp($start, $end) > 0) {
                    continue;
                }
                $key = strtolower($warehouse).'.'.strtolower($carrier).'.'.$p.$r;
                $out['zones'][] = ['zone_key' => $key, 'warehouse_code' => $warehouse, 'carrier' => $carrier, 'service' => $service, 'destination_country' => 'US',
                    'postal_match_type' => $start === $end ? ($gofo ? 'exact' : 'prefix') : 'range', 'postal_start' => $start, 'postal_end' => $end,
                    'zone_label' => $label, 'review_status' => 'needs_review', 'source_sheet' => $name, 'source_cells' => $p.$r.':'.($e ?? $z).$r];
            }
        }
        if ($limit === 12000) {
            $out['warnings'][] = $name.' 分区读取达到上限，须核对完整性。';
        }
    }

    private function de(Worksheet $s, array &$out): void
    {
        $local = $s->getTitle() === 'DE-DHL （本地）';
        $bands = [];
        $start = $local ? 5 : 13;
        $end = $local ? 13 : 17;
        for ($r = $start; $r <= $end; $r++) {
            $raw = $this->value($s, ($local ? 'B' : 'C').$r);
            $price = $this->value($s, ($local ? 'C' : 'D').$r);
            if (! is_numeric($price) || $raw === null) {
                throw new RuntimeException('德国报价必需档位缺失');
            }
            if ($local) {
                $ok = preg_match('/^(\d+(?:\.\d+)?)-(\d+(?:\.\d+)?)KG$/iD', $raw, $m);
            } else {
                $ok = preg_match('/^(\d+(?:\.\d+)?)\s*<\s*x\s*≤\s*(\d+(?:\.\d+)?)\s*kg$/iuD', $raw, $m);
            }
            if (! $ok) {
                throw new RuntimeException('德国报价重量档位格式变化，须审核');
            }
            $bands[] = $this->band(count($bands) + 1, $m[1], $m[2], $price, null, $local ? null : '0.8', ($local ? 'B' : 'C').$r.':'.($local ? 'C' : 'D').$r, ['weight' => $raw, 'price' => $price]);
        }
        $rule = $local ? ['basis' => 'actual', 'increment' => 1, 'max_actual_kg' => 31.5, 'max_dimensions_cm' => [120, 60, 60], 'min_dimensions_cm' => [15, 11, 1], 'max_girth_cm' => 360, 'fuel_percent' => 3, 'seasonal_fee' => 0.19, 'seasonal_mmdd' => ['11-01', '12-31']] : ['basis' => 'max', 'dim_divisor' => 6000, 'volume_unit' => 'cm3_kg'];
        $this->component($out, 'de.dhl.domestic.'.($local ? 'transport' : 'handling'), 'DE', 'DHL', 'domestic', 'DE', null, $local ? 'transport' : 'handling', 'EUR', 'kg', 'fixed_band', $rule, $s->getTitle(), ($local ? 'B5:C13' : 'C13:D17'), $bands);
    }

    private function sd(Worksheet $s, array &$out): void
    {
        $countries = ['美国' => 'US', '加拿大' => 'CA', '墨西哥' => 'MX', '英国' => 'GB', '德国' => 'DE', '法国' => 'FR', '爱尔兰' => 'IE', '以色列' => 'IL', '意大利' => 'IT', '西班牙' => 'ES', '荷兰' => 'NL', '比利时' => 'BE', '奥地利' => 'AT', '瑞典' => 'SE', '丹麦' => 'DK', '芬兰' => 'FI', '波兰' => 'PL', '捷克' => 'CZ', '澳大利亚' => 'AU', '日本' => 'JP', '新西兰' => 'NZ', '葡萄牙' => 'PT', '挪威' => 'NO', '瑞士' => 'CH', '韩国' => 'KR'];
        $groups = [];
        $country = null;
        for ($r = 6; $r <= 400; $r++) {
            if ($this->value($s, 'A'.$r) === '价格使用说明') {
                break;
            }
            $name = $this->value($s, 'C'.$r);
            if ($name !== null) {
                $country = $countries[$name] ?? null;
            }
            $band = $this->value($s, 'E'.$r);
            $per = $this->value($s, 'F'.$r);
            $fixed = $this->value($s, 'G'.$r);
            if (! $country || ! $band || ! is_numeric($per) || ! is_numeric($fixed) || ! preg_match('/^(\d+(?:\.\d+)?)-(\d+(?:\.\d+)?)$/D', $band, $m)) {
                continue;
            }
            $groups[$country][] = $this->band(count($groups[$country] ?? []) + 1, $m[1], $m[2], $fixed, $per, null, 'E'.$r.':G'.$r, ['country' => $name, 'weight' => $band, 'price_per_kg' => $per, 'base' => $fixed], true);
        }
        foreach ($groups as $country => $bands) {
            $rule = ['basis' => 'max', 'dim_divisor' => 8000, 'volume_unit' => 'cm3_kg', 'increment' => 0.001, 'handling_included' => true];
            if (in_array($country, ['US', 'CA'], true)) {
                $rule['minimum'] = 0.05;
            }
            if ($country === 'US') {
                $rule['excluded_zip_prefixes'] = ['006', '007', '008', '009', '967', '968', '969', '995', '996', '997', '998', '999'];
            }
            $this->component($out,'factory.sd.economy_battery.'.strtolower($country),'FACTORY','SPEEDA','sd_economy_battery',$country,null,'transport','CNY','kg','linear_band',$rule,$s->getTitle(),'E6:G61',$bands);
        }
    }
}
