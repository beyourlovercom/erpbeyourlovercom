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
            'speeda_us' => ['USPS尾程', 'GOFO尾程', '美东仓USPS分区表', '美西仓USPS分区表', 'GOFO美东分区表', '出入库订单出操作费'],
            'tianyun_de' => ['DE-DHL （本地）', '库内基础服务费用', 'DE -DHL （国际）'],
            'speeda_sd' => ['SD标准-特货', 'SDZH欧洲含税-普货', 'SDZH欧洲含税-带电', 'SDZH欧洲含税-化妆品', 'SD标准挂号-带电', 'SD经济挂号-带电', 'SD标准挂号-普货', 'SD经济挂号-普货', 'SD岛屿专线-普货', 'SD-S小包'],
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
                $max = str_contains($worksheetName, '分区表') ? 12000 : 600;

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
                    } elseif ($name === '出入库订单出操作费') {
                        $this->usHandling($sheet, $out);
                    } else {
                        $this->zones($sheet, $out);
                    }
                } elseif ($source === 'tianyun_de') {
                    if ($name === 'DE -DHL （国际）') {
                        $this->deInternational($sheet, $out);
                    } else {
                        $this->de($sheet, $out);
                    }
                } else {
                    $this->sd($sheet, $out);
                }
                $out['parsed_sheets'][] = $name;
            }
        } finally {
            $book->disconnectWorksheets();
        }
        if ($source === 'speeda_sd' && in_array('邮编分区', $names, true)) {
            $cells = (new LogisticsRatePostalEvidenceReader)->read($path, '邮编分区', ['C', 'D', 'F', 'G', 'I', 'J', 'L', 'M', 'O', 'P', 'R', 'S', 'W', 'AL', 'AM', 'BN', 'BO'], 12000);
            $this->sdPostalEvidence($cells, $out);
            $out['parsed_sheets'][] = '邮编分区';
        }
        $out['warnings'][] = '全部解析行默认待审核。未解析工作表及原始附加费/禁限运条款须逐项确认，禁止自动启用。';
        if ($source === 'speeda_us') {
            $out['warnings'][] = '依已冻结仓库规范及速达美东账单来源，原表 US-NJ 映射 ERP US-NY；原表速达 US-LA 独立标记 SPEEDA-US-LA，绝不映射 MorePlus。USPS 为 Ground Advantage，协议有效期、操作费边界、邮编特殊标记及附加费仍待审核。';
        }
        if ($source === 'tianyun_de') {
            $out['warnings'][] = '德国本地和国际基础运费、操作费均保留；国际计费冲突不自动解决。';
        }
        if ($source === 'speeda_sd') {
            $out['warnings'][] = '各小包渠道均保留基础费率，区域邮编表、渠道体积/进位、品类限制及附加费仍需逐项审核。';
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
        foreach ($gofo ? ['US-NY'] : ['US-NY', 'SPEEDA-US-LA'] as $warehouse) {
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
        $warehouse = str_contains($name, '美西') ? 'SPEEDA-US-LA' : 'US-NY';
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
            $bands[] = $this->band(count($bands) + 1, $m[1], $m[2], $price, null, null, ($local ? 'B' : 'C').$r.':'.($local ? 'C' : 'D').$r, ['weight' => $raw, 'price' => $price]);
        }
        $rule = ['basis' => 'review_required'];
        if ($local) {
            $fuel = $this->value($s, 'B17');
            $weightRule = $this->value($s, 'B18');
            $sizeRule = $this->value($s, 'B19');
            $maxRule = $this->value($s, 'B20');
            $seasonal = $this->value($s, 'C25');
            $seasonalNote = $this->value($s, 'E25');
            if ($fuel && preg_match('/燃油费率\s*(\d+(?:\.\d+)?)%/u', $fuel, $f) && str_contains($fuel, '（运费+附加费）')
                && $weightRule && str_contains($weightRule, '实重计费') && str_contains($weightRule, '向上进一取整')
                && $sizeRule && preg_match('/L＜(\d+(?:\.\d+)?)cm，W＜(\d+(?:\.\d+)?)cm，H＜(\d+(?:\.\d+)?)cm/u', $sizeRule, $sz)
                && preg_match('/＜(\d+(?:\.\d+)?)cm，包裹最小尺寸：(\d+(?:\.\d+)?)cm\*(\d+(?:\.\d+)?)cm\*(\d+(?:\.\d+)?)cm/u', $sizeRule, $g)
                && $maxRule && preg_match('/重量限制：(\d+(?:\.\d+)?)kg/u', $maxRule, $mw)
                && is_numeric($seasonal) && (float) $seasonal >= 0 && $seasonalNote
                && preg_match('/(\d{1,2})\s*月\s*(\d{1,2})\s*号至\s*(\d{1,2})\s*月\s*(\d{1,2})\s*号/u', $seasonalNote, $dt)
                && checkdate((int) $dt[1], (int) $dt[2], 2024) && checkdate((int) $dt[3], (int) $dt[4], 2024)) {
                $rule = ['basis' => 'actual', 'increment' => 1, 'max_actual_kg' => (float) $mw[1],
                    'max_dimensions_cm' => array_map('floatval', array_slice($sz, 1, 3)),
                    'min_dimensions_cm' => array_map('floatval', array_slice($g, 2, 3)),
                    'max_girth_cm' => (float) $g[1], 'fuel_percent' => (float) $f[1],
                    'seasonal_fee' => (float) $seasonal, 'seasonal_mmdd' => [sprintf('%02d-%02d', $dt[1], $dt[2]), sprintf('%02d-%02d', $dt[3], $dt[4])]];
            }
            foreach ($bands as &$band) {
                $band['raw_values']['rule_evidence'] = ['B17' => $fuel, 'B18' => $weightRule, 'B19' => $sizeRule, 'B20' => $maxRule, 'C25' => $seasonal, 'E25' => $seasonalNote];
            }
            unset($band);
        } else {
            $note = $this->value($s, 'E12');
            if ($note && str_contains($note, '体积重与实重取大值') && preg_match('/体积系数除以(\d+(?:\.\d+)?)/u', $note, $d)
                && preg_match('/每增加1件加收\s*€\s*(\d+(?:\.\d+)?)/u', $note, $e) && (float) $d[1] > 0) {
                $rule = ['basis' => 'max', 'dim_divisor' => (float) $d[1], 'volume_unit' => 'cm3_kg'];
                foreach ($bands as &$band) {
                    $band['per_extra_item_amount'] = $e[1];
                }
                unset($band);
            }
            foreach ($bands as &$band) {
                $band['raw_values']['note'] = $note;
            }
            unset($band);
        }
        if ($rule['basis'] === 'review_required') {
            $out['warnings'][] = $s->getTitle().' 计费说明未匹配已支持结构，费率保留待核验。';
        }
        $this->component($out, 'de.dhl.domestic.'.($local ? 'transport' : 'handling'), 'DE', 'DHL', 'domestic', 'DE', null, $local ? 'transport' : 'handling', 'EUR', 'kg', 'fixed_band', $rule, $s->getTitle(), ($local ? 'B5:C13' : 'C13:D17'), $bands);
    }

    private function usHandling(Worksheet $s, array &$out): void
    {
        $bands = [];
        for ($r = 3; $r <= 8; $r++) {
            $weight = $this->value($s, 'B'.$r);
            $price = $this->value($s, 'D'.$r);
            if (! $weight || ! preg_match('/^(\d+)<X<(\d+)LB/iD', substr($weight, 0, strpos($weight, '(') ?: strlen($weight)), $m) || ! is_numeric($price)) {
                throw new RuntimeException('美国仓操作费档位需审核');
            }
            $band = $this->band(count($bands) + 1, $m[1], $m[2], $price, null, '0.1', 'B'.$r.':F'.$r, ['weight' => $weight, 'note' => $this->value($s, 'F3')]);
            $band['max_inclusive'] = false;
            $bands[] = $band;
        }
        foreach ([['US-NY', 'USPS', 'ground_advantage'], ['SPEEDA-US-LA', 'USPS', 'ground_advantage'], ['US-NY', 'GOFO', 'express']] as [$warehouse,$carrier,$service]) {
            $this->component($out, strtolower($warehouse).'.'.strtolower($carrier).'.handling', $warehouse, $carrier, $service, 'US', null, 'handling', 'USD', 'lb', 'fixed_band', ['basis' => 'review_required'], '出入库订单出操作费', 'B3:F8', $bands);
        }
        $out['warnings'][] = '美国仓操作费原表使用严格开区间，恰好1/5/10/20/30/50磅时需供应商确认；包装耗材不包含。';
    }

    private function deInternational(Worksheet $s, array &$out): void
    {
        for ($r = 4; $r <= 35; $r++) {
            $country = $this->value($s, 'D'.$r);
            $max = $this->value($s, 'F'.$r);
            $per = $this->value($s, 'G'.$r);
            $base = $this->value($s, 'H'.$r);
            if (! $country || ! preg_match('/^[A-Z]{2}$/D', $country) || ! $max || ! preg_match('/^(\d+(?:\.\d+)?)kg$/iD', $max, $m) || ! is_numeric($per) || ! is_numeric($base)) {
                throw new RuntimeException('德国国际报价行结构变化，须审核：'.$r);
            }
            $rule = ['basis' => 'review_required'];
            $raw = ['country' => $this->value($s, 'C'.$r), 'max_weight' => $max, 'price_per_kg' => $per, 'base_per_ticket' => $base,
                'formula_header' => $this->value($s, 'I3'), 'formula_note' => $this->value($s, 'J7'), 'multi_package_example' => $this->value($s, 'J9'), 'weight_rule' => $this->value($s, 'B40')];
            $this->component($out, 'de.dhl.international.'.strtolower($country), 'DE', 'DHL', 'international', $country, null, 'transport', 'EUR', 'kg', 'linear_band', $rule, $s->getTitle(), 'D'.$r.':H'.$r, [$this->band(1, '0', $m[1], $base, $per, null, 'F'.$r.':H'.$r, $raw)]);
        }
        $out['warnings'][] = '德国国际报价已保留原始单价；I3/J7续重公式及J9每票基础费与包裹关系冲突，未核准前不计算。';
    }

    private function countryMap(): array
    {
        return [
            '美国' => 'US', '加拿大' => 'CA', '墨西哥' => 'MX', '英国' => 'GB', '德国' => 'DE', '法国' => 'FR', '爱尔兰' => 'IE', '以色列' => 'IL', '意大利' => 'IT', '西班牙' => 'ES', '荷兰' => 'NL', '比利时' => 'BE', '奥地利' => 'AT', '瑞典' => 'SE', '丹麦' => 'DK', '芬兰' => 'FI', '波兰' => 'PL', '捷克' => 'CZ', '捷克共和国' => 'CZ', '澳大利亚' => 'AU', '日本' => 'JP', '新西兰' => 'NZ', '葡萄牙' => 'PT', '挪威' => 'NO', '瑞士' => 'CH', '韩国' => 'KR',
            '匈牙利' => 'HU', '卢森堡' => 'LU', '罗马尼亚' => 'RO', '立陶宛' => 'LT', '斯洛文尼亚' => 'SI', '哥伦比亚' => 'CO', '智利' => 'CL', '巴西' => 'BR', '新加坡' => 'SG', '沙特阿拉伯' => 'SA', '斯洛伐克' => 'SK', '克罗地亚' => 'HR', '保加利亚' => 'BG', '马耳他' => 'MT', '希腊' => 'GR', '塞浦路斯' => 'CY', '乌克兰' => 'UA', '爱沙尼亚' => 'EE', '拉脱维亚' => 'LV', '冰岛' => 'IS', '秘鲁' => 'PE', '厄瓜多尔' => 'EC', '马来西亚' => 'MY', '菲律宾' => 'PH', '土耳其' => 'TR', '哈萨克斯坦' => 'KZ', '阿联酋' => 'AE', '卡塔尔' => 'QA', '科威特' => 'KW', '巴林' => 'BH', '阿曼' => 'OM', '尼日利亚' => 'NG', '加纳' => 'GH', '乌干达' => 'UG', '肯尼亚' => 'KE', '摩洛哥' => 'MA', '坦桑尼亚' => 'TZ', '卢旺达' => 'RW', '安哥拉' => 'AO', '塞尔维亚' => 'RS',
            '留尼汪' => 'RE', '马其顿' => 'MK', '阿尔巴尼亚' => 'AL', '俄罗斯' => 'RU', '摩尔多瓦' => 'MD', '阿根廷' => 'AR', '乌拉圭' => 'UY', '巴拉圭' => 'PY', '洪都拉斯' => 'HN', '尼加拉瓜' => 'NI', '玻利维亚' => 'BO', '萨尔瓦多' => 'SV', '哥斯达黎加' => 'CR', '危地马拉' => 'GT', '巴拿马' => 'PA', '多米尼加共和国' => 'DO', '特立尼达和多巴哥' => 'TT', '伊拉克' => 'IQ', '泰国' => 'TH', '越南' => 'VN', '斯里兰卡' => 'LK', '毛里求斯' => 'MU', '马达加斯加' => 'MG', '赞比亚' => 'ZM', '阿尔及利亚' => 'DZ', '南非' => 'ZA',
        ];
    }

    private function sd(Worksheet $s, array &$out): void
    {
        $title = $s->getTitle();
        if ($title === 'SD-S小包') {
            $this->sdSmall($s, $out);

            return;
        }
        $layout = match ($title) {
            'SD标准-特货' => ['standard_special', 3, 'A', 'C', 'E', 'D', 'G'],
            'SDZH欧洲含税-普货' => ['europe_tax_general', 4, 'B', 'E', 'G', 'F', 'H'],
            'SDZH欧洲含税-带电' => ['europe_tax_battery', 4, 'B', 'E', 'G', 'F', 'H'],
            'SDZH欧洲含税-化妆品' => ['europe_tax_cosmetic', 4, 'B', 'E', 'G', 'F', 'H'],
            'SD标准挂号-带电' => ['standard_battery', 4, 'B', 'C', 'D', 'E', 'H'],
            'SD经济挂号-带电' => ['economy_battery', 6, 'C', 'E', 'F', 'G', 'K'],
            'SD标准挂号-普货' => ['standard_general', 6, 'C', 'E', 'F', 'G', 'K'],
            'SD经济挂号-普货' => ['economy_general', 6, 'C', 'E', 'F', 'G', 'K'],
            'SD岛屿专线-普货' => ['island_general', 5, 'C', 'E', 'F', 'G', 'K'],
            default => throw new RuntimeException('未知SD报价布局'),
        };
        [$service,$start,$nameCol,$weightCol,$perCol,$baseCol,$noteCol] = $layout;
        $map = $this->countryMap();
        $groups = [];
        $label = null;
        $country = null;
        $zone = null;
        $sourceRegion = null;
        $note = null;
        $skipped = [];
        $limit = min(600, $s->getHighestDataRow());
        for ($r = $start; $r <= $limit; $r++) {
            $name = $this->value($s, $nameCol.$r);
            if ($name !== null) {
                $label = $name;
                $country = $map[$name] ?? null;
                $zone = null;
                if ($country === null) {
                    foreach (['澳大利亚' => 'AU', '马来西亚' => 'MY', '菲律宾' => 'PH'] as $prefix => $iso) {
                        if (str_starts_with($name, $prefix)) {
                            $country = $iso;
                            $zone = mb_substr(trim(mb_substr($name, mb_strlen($prefix))), 0, 32);
                            break;
                        }
                    }
                }
                $sourceRegion = $nameCol === 'C' ? $this->value($s, 'D'.$r) : null;
                $note = $this->value($s, $noteCol.$r);
            }
            $raw = $this->value($s, $weightCol.$r);
            $per = $this->value($s, $perCol.$r);
            $base = $this->value($s, $baseCol.$r);
            if (! $raw || ! is_numeric($per) || ! is_numeric($base) || (float) $per < 0 || (float) $base < 0) {
                continue;
            }
            $range = preg_match('/^(\d+(?:\.\d+)?)\s*-\s*(\d+(?:\.\d+)?)(?:KG)?$/iD', $raw, $m);
            $open = preg_match('/^(\d+(?:\.\d+)?)\s*<\s*W\s*≤\s*(\d+(?:\.\d+)?)$/iuD', $raw, $m2);
            if (! $range && ! $open) {
                $skipped[] = $weightCol.$r;

                continue;
            }
            if ($open) {
                $m = $m2;
            }
            if (! $label || ! $country) {
                $skipped[] = $weightCol.$r.':'.($label ?? '未知国家');

                continue;
            }
            // Preserve ambiguous destination zones/regions separately; they remain review-only.
            $identity = $country.'|'.($zone ?? '').'|'.($sourceRegion ?? '');
            $key = 'factory.sd.'.$service.'.'.strtolower($country);
            if ($zone !== null || $sourceRegion !== null) {
                $key .= '.'.substr(hash('sha256', $identity), 0, 12);
            }
            $group = $groups[$identity] ?? ['country' => $country, 'zone' => $zone, 'key' => $key, 'first' => $r, 'last' => $r, 'bands' => []];
            $prior = $group['bands'] === [] ? null : end($group['bands']);
            $inclusive = ! $open && ($prior === null || (float) $m[1] > (float) $prior['max_weight']);
            $lastCol = max($weightCol, $perCol, $baseCol);
            $cells = $weightCol.$r.':'.$lastCol.$r;
            $letters = range('A', 'K');
            $rowEvidence = [];
            foreach ($letters as $col) {
                $v = $this->value($s, $col.$r);
                if ($v !== null) {
                    $rowEvidence[$col] = $v;
                }
            }
            $group['bands'][] = $this->band(count($group['bands']) + 1, $m[1], $m[2], $base, $per, null, $cells, ['country_label' => $label, 'region' => $sourceRegion, 'weight' => $raw, 'price_per_kg' => $per, 'base' => $base, 'note' => $note, 'row' => $rowEvidence], $inclusive);
            $group['last'] = $r;
            $groups[$identity] = $group;
        }
        $weightEvidence = [];
        $inside = false;
        for ($r = 1; $r <= $limit; $r++) {
            $line = $this->value($s, 'A'.$r);
            if (! $inside && $line && preg_match('/^一、计费(?:重量|方式)$/u', $line)) {
                $inside = true;

                continue;
            }
            if (! $inside || ! $line) {
                continue;
            }
            if (preg_match('/^[二三四五六七八九十]+、/u', $line)) {
                break;
            }
            $weightEvidence['A'.$r] = $line;
            if (count($weightEvidence) >= 18) {
                break;
            }
        }
        // Source-specific restriction: a catalog with unknown cargo classification must
        // not treat an explicitly prohibited US route as an applicable cheap channel.
        $restrictedUsCell = null;
        for ($r = 1; $r <= $limit; $r++) {
            $line = $this->value($s, 'A'.$r);
            if ($line && str_contains($line, '美国') && str_contains($line, '不接受成人用品')) {
                $restrictedUsCell = 'A'.$r;
                break;
            }
        }
        foreach ($groups as $group) {
            // A price-table import does not certify channel-specific dimensional, rounding or restriction rules.
            $rule = $this->sdWeightRule($service, $group['country'], $group['zone']);
            $evidenceText = implode("\n", $weightEvidence);
            // A future changed formula must not silently reuse the old dimensional/rounding assumptions.
            if (($rule['basis'] ?? null) === 'max') {
                $recognized = $service === 'standard_special'
                    ? str_contains($evidenceText, '以G为单位进位，最低50G计费') && str_contains($evidenceText, '材积/8000')
                    : str_contains($evidenceText, '以G为单位进位') && str_contains($evidenceText, '长*宽*高/8000');
                if (($rule['dim_divisor'] ?? null) === 6000) {
                    $recognized = $recognized && str_contains($evidenceText, '/6000');
                }
                if (isset($rule['rounding_tiers'])) {
                    $recognized = $recognized && str_contains($evidenceText, '2KG以内按0.01KG进位') && str_contains($evidenceText, '2-10KG按0.5KG进位') && str_contains($evidenceText, '10KG以上按1KG进位');
                }
                if (($rule['minimum'] ?? null) === 0.05) {
                    $recognized = $recognized && str_contains($evidenceText, '50G');
                }
                if (($rule['minimum'] ?? null) === 0.02) {
                    $recognized = $recognized && str_contains($evidenceText, '20G');
                }
                if (($rule['minimum'] ?? null) === 0.5) {
                    $recognized = $recognized && str_contains($evidenceText, '0.5KG');
                }
                if (($rule['minimum'] ?? null) === 0.1) {
                    $recognized = $recognized && str_contains($evidenceText, '0.1KG');
                }
                if (! $recognized) {
                    $rule = ['basis' => 'review_required'];
                }
            }
            if ($group['country'] === 'US' && $restrictedUsCell !== null) {
                $rule['catalog_restriction_source_cell'] = $restrictedUsCell;
            }
            foreach ($group['bands'] as &$band) {
                $band['raw_values']['weight_rule_evidence'] = $weightEvidence;
            }
            unset($band);
            $this->component($out, $group['key'], 'FACTORY', 'SPEEDA', 'sd_'.$service, $group['country'], $group['zone'], 'transport', 'CNY', 'kg', 'linear_band', $rule, $title, $weightCol.$group['first'].':'.max($weightCol, $perCol, $baseCol).$group['last'], $group['bands']);
        }
        if ($skipped) {
            $out['warnings'][] = $title.' 存在未结构化数值行：'.implode('、', array_slice($skipped, 0, 30)).'；原表保留。';
        }
        if ($limit === 600) {
            $out['warnings'][] = $title.' 达到行读取上限，须核实剩余内容。';
        }
    }

    /** Region/postal evidence, not an approved service-to-zone binding. */
    private function sdPostalEvidence(array $cells, array &$out): void
    {
        $layouts = [
            ['C', 'D', null, 'KR', 'evidence.kr_excluded', 'excluded', 3, 5, '【韩国】无服务邮编'],
            ['F', 'G', null, 'KR', 'evidence.kr_remote_legacy', 'remote', 3, null, '【韩国】偏远邮编,可服务'],
            ['I', 'J', 'I', 'PH', 'evidence.ph_zone', null, 3, 4, '【菲律宾】分区邮编'],
            ['L', 'M', 'L', 'AU', 'evidence.au_zone_a', null, 3, 4, '【澳大利亚】分区邮编'],
            ['O', 'P', null, 'AU', 'evidence.au_excluded', 'excluded', 3, 4, '【澳大利亚】无服务邮编'],
            ['R', 'S', 'R', 'MY', 'evidence.my_zone', null, 4, 5, '【马来西亚】分区邮编'],
            ['W', 'W', null, 'US', 'evidence.us_island', 'eligible', 4, 5, '美国岛屿可服务邮编'],
            ['AL', 'AM', 'AL', 'AU', 'evidence.au_zone_b', null, 3, 4, '【澳大利亚】分区邮编'],
            ['BN', 'BO', null, 'KR', 'evidence.kr_remote', 'remote', 3, 5, '【韩国】偏远邮编,可服务'],
        ];
        $limit = 12000;
        foreach ($layouts as [$header,$postalCol,$labelCol,$country,$service,$static,$start,$digits,$expected]) {
            if (($cells[$header.'1'] ?? null) !== $expected) {
                $out['warnings'][] = '邮编分区 '.$header.'1 标题变化，未解析该区块。';

                continue;
            }
            for ($r = $start; $r <= $limit; $r++) {
                $zip = $cells[$postalCol.$r] ?? null;
                if ($zip === null) {
                    continue;
                }
                // Do not invent lost leading zeroes or reinterpret non-postal codes.
                if ($digits !== null && ! preg_match('/^\d{'.$digits.'}$/D', $zip)) {
                    continue;
                }
                if (strlen($zip) > 32 || ! preg_match('/^[A-Z0-9 -]+$/iD', $zip)) {
                    continue;
                }
                $label = $static ?? ($cells[$labelCol.$r] ?? null);
                if (! $label || mb_strlen($label) > 32) {
                    continue;
                }
                $out['zones'][] = ['zone_key' => $service.'.'.$postalCol.$r, 'warehouse_code' => 'FACTORY', 'carrier' => 'SPEEDA', 'service' => $service,
                    'destination_country' => $country, 'postal_match_type' => 'exact', 'postal_start' => $zip, 'postal_end' => null, 'zone_label' => $label,
                    'review_status' => 'needs_review', 'source_sheet' => '邮编分区', 'source_cells' => ($labelCol ?? $postalCol).$r.':'.$postalCol.$r];
            }
        }
        $out['warnings'][] = '邮编分区：已结构化韩国、菲律宾、澳大利亚两套分区、马来西亚、美国岛屿等九个证据区块；其余产品线及目的地名单保留原文件，未自动绑定渠道。';
    }

    private function sdWeightRule(string $service, string $country, ?string $zone): array
    {
        if ($service === 'small' || $zone !== null || str_starts_with($service, 'europe_tax_')) {
            return ['basis' => 'review_required'];
        }
        $rule = ['basis' => 'max', 'dim_divisor' => 8000, 'volume_unit' => 'cm3_kg', 'increment' => 0.001, 'handling_included' => true];
        if ($service === 'standard_special') {
            $rule['minimum'] = 0.05;
            if ($country === 'AU') {
                $rule['minimum'] = 0.01;
                $rule['increment'] = 0.01;
            }
            if ($country === 'IL') {
                $rule['minimum'] = 0.1;
            }

            return $rule;
        }
        if (in_array($service, ['economy_battery', 'economy_general'], true)) {
            if (in_array($country, ['US', 'CA'], true)) {
                $rule['minimum'] = 0.05;
            }
        } elseif (in_array($service, ['standard_battery', 'standard_general', 'island_general'], true)) {
            if (in_array($country, ['US', 'CA', 'BR', 'PE', 'TR', 'EC'], true)) {
                $rule['minimum'] = 0.05;
            }
            if (in_array($country, ['CO', 'UY', 'PR', 'PY', 'HN', 'NI', 'BO', 'SV', 'CR', 'GT', 'PA', 'DO', 'TT'], true)) {
                $rule['minimum'] = 0.02;
            }
            if (in_array($country, ['SA', 'AE', 'IQ'], true)) {
                $rule['minimum'] = 0.1;
            }
            if (in_array($country, ['QA', 'KW', 'BH', 'OM', 'KZ'], true)) {
                $rule['minimum'] = 0.1;
                $rule['increment'] = 0.1;
            }
            if ($country === 'JP') {
                $rule['minimum'] = 0.5;
                $rule['increment'] = 0.5;
            }
            $africa = ['NG', 'GH', 'UG', 'KE', 'MA', 'TZ', 'RW', 'AO', 'MU', 'MG', 'ZM', 'DZ', 'ZA'];
            $specified = ['NG', 'GH', 'UG', 'KE', 'MA', 'PK', 'TZ', 'RW', 'AO'];
            if (in_array($country, $specified, true) || ($service === 'standard_general' && in_array($country, $africa, true))) {
                unset($rule['increment']);
                $rule['rounding_tiers'] = [['up_to' => 2, 'increment' => 0.01], ['up_to' => 10, 'increment' => 0.5], ['up_to' => null, 'increment' => 1]];
            }
            if ($country === 'ZA' || ($service === 'standard_general' && in_array($country, $africa, true))) {
                $rule['dim_divisor'] = 6000;
            }
        } else {
            return ['basis' => 'review_required'];
        }
        if ($country === 'US' && in_array($service, ['economy_battery', 'economy_general', 'standard_battery', 'standard_general'], true)) {
            $rule['excluded_zip_prefixes'] = ['006', '007', '008', '009', '967', '968', '969', '995', '996', '997', '998', '999'];
        }

        return $rule;
    }

    private function sdSmall(Worksheet $s, array &$out): void
    {
        $skipped = [];
        for ($r = 4; $r <= min(600, $s->getHighestDataRow()); $r++) {
            $country = $this->value($s, 'D'.$r);
            $per = $this->value($s, 'E'.$r);
            $base = $this->value($s, 'F'.$r);
            $note = $this->value($s, 'G'.$r);
            if (! $country || ! preg_match('/^[A-Z]{2}$/D', $country)) {
                continue;
            }
            if (! is_numeric($per) || ! is_numeric($base) || str_contains($note ?? '', '暂停服务') || (float) $per < 0 || (float) $base < 0) {
                $skipped[] = $country;

                continue;
            }
            $raw = ['country_label' => $this->value($s, 'C'.$r), 'zone' => $this->value($s, 'A'.$r), 'zone_registered' => $this->value($s, 'B'.$r), 'note' => $note];
            $this->component($out, 'factory.sd.small.'.strtolower($country), 'FACTORY', 'SPEEDA', 'sd_small', $country, null, 'transport', 'CNY', 'kg', 'linear_band', ['basis' => 'review_required'], $s->getTitle(), 'A'.$r.':G'.$r, [$this->band(1, '0', '2', $base, $per, null, 'D'.$r.':G'.$r, $raw)]);
        }
        if ($skipped) {
            $out['warnings'][] = 'SD-S小包 暂停或无有效报价国家：'.implode('、', array_unique($skipped)).'；不生成可用报价。';
        }
    }
}
