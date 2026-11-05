<?php

namespace App\Modules\Orders\Application;

use App\Modules\Logistics\Application\OverseaFiles\ProviderXlsxTableReader;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use DateTimeImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

final class OrderOmsBatchImport
{
    public const MAX_ORDERS = 200;
    public const MAX_ROWS = 2000;
    public const MAX_ITEMS_PER_ORDER = 100;
    public const FIELDS = [
        'external_order_number', 'order_date', 'recipient_name', 'phone', 'country',
        'province_state', 'city', 'address_line_1', 'address_line_2', 'postal_code',
        'currency', 'order_note', 'sku', 'quantity', 'unit_price',
    ];
    private const ORDER_FIELDS = [
        'order_date', 'recipient_name', 'phone', 'country',
        'province_state', 'city', 'address_line_1', 'address_line_2',
        'postal_code', 'currency', 'order_note',
    ];
    private const ORDER_REQUIRED = [
        'order_date', 'recipient_name', 'country', 'province_state', 'city', 'address_line_1',
    ];
    private const LINE_REQUIRED = ['external_order_number', 'sku', 'quantity', 'unit_price'];

    public function __construct(
        private readonly ProviderXlsxTableReader $reader,
        private readonly OrderService $orders,
    ) {}

    public function template(): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('批量订单导入');
        foreach (self::FIELDS as $i => $key) {
            $sheet->setCellValueExplicit([$i + 1, 1], $key, DataType::TYPE_STRING);
            $column = $sheet->getColumnDimensionByColumn($i + 1);
            $column->setWidth(in_array($key, ['external_order_number', 'address_line_1', 'address_line_2', 'order_note'], true) ? 24 : 17);
        }
        $lastCol = $sheet->getHighestColumn();
        $sheet->getStyle('A1:'.$lastCol.'1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1:'.$lastCol.'1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF64748B');
        foreach ([...self::ORDER_REQUIRED, ...self::LINE_REQUIRED] as $key) {
            $index = array_search($key, self::FIELDS, true);
            $cell = $sheet->getCell([$index + 1, 1]);
            $cell->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFBD55');
            $cell->getStyle()->getFont()->getColor()->setARGB('FF172B4D');
        }
        // Preserve leading zeros in external order numbers and SKU strings.
        foreach (['A', 'M'] as $letter) {
            $sheet->getStyle($letter.'2:'.$letter.'2002')->getNumberFormat()->setFormatCode('@');
        }
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:'.$lastCol.'1');
        $help = $book->createSheet();
        $help->setTitle('使用说明');
        $tips = [
            ['BYL ERP 批量新建客户订单 V1', ''],
            ['一行一个 SKU', '同一订单号出现多行即一张订单，一行只能填写一个 SKU、数量、单价；无须横向重复五组商品'],
            ['订单分组规则', '每个商品行必须重复填写 external_order_number；同订单号不区分大小写，建议将相同订单的行连续放置'],
            ['订单资料', '只在这个订单首次出现的行填写地址、日期、币种、备注等；后续行留空继承；若重复填写却与首行不同将报错'],
            ['黄色表头', '必填；灰色表头可留空。不要删除、修改、重排第一行字段名称'],
            ['external_order_number', '同一个订单的所有 SKU 行必须是完全相同的来源订单号；不同订单使用不同号；不可与 ERP 已有订单重复'],
            ['order_date', '填写 YYYY-MM-DD，例如 2026-11-05，勿使用 Excel 自动日期格式'],
            ['sku', '每个 XLSX 数据行一个 SKU；必须与 ERP 产品库中唯一 SKU 精确匹配'],
            ['quantity', '当前 SKU 的购买数量，必须为 1–99999 的正整数'],
            ['unit_price', '当前 SKU 商品单价，0 或正数，最多两位小数'],
            ['country', '建议使用两位 ISO 国家代码，例如 US、DE、CA'],
            ['recipient_name / province_state / city / address_line_1', '批量建单要求完整收件信息'],
            ['currency', '默认为 USD，支持三位大写货币代码'],
            ['批次限制', '最多 200 个订单、2000 行商品、每单最多 100 行商品；文件上限 5MB'],
            ['导入方式', '选择文件 → 服务端预检 → 检查错误 → 确认创建；预检不会创建任何订单'],
            ['安全', '仅创建 ERP 手动客户订单，不触发 WooCommerce 同步、仓库分配或自动发货'],
        ];
        foreach ($tips as $n => [$key, $value]) {
            $help->setCellValue([1, $n + 1], $key);
            $help->setCellValue([2, $n + 1], $value);
        }
        $example = $book->createSheet();
        $example->setTitle('示例-勿上传');
        foreach (self::FIELDS as $i => $name) $example->setCellValueExplicit([$i+1, 1], $name, DataType::TYPE_STRING);
        $first = array_fill_keys(self::FIELDS, '');
        $first['external_order_number'] = 'DEMO-1001';
        $first['order_date'] = '2026-11-05';
        $first['recipient_name'] = 'Example Customer';
        $first['phone'] = '5551234567';
        $first['country'] = 'US';
        $first['province_state'] = 'CA';
        $first['city'] = 'Los Angeles';
        $first['address_line_1'] = '123 Example Street';
        $first['postal_code'] = '90001';
        $first['currency'] = 'USD';
        $first['sku'] = 'MF414A';
        $first['quantity'] = '2';
        $first['unit_price'] = '79.00';
        $second = array_fill_keys(self::FIELDS, '');
        $second['external_order_number'] = 'DEMO-1001';
        $second['sku'] = 'A1';
        $second['quantity'] = '1';
        $second['unit_price'] = '0.00';
        $third = $first;
        $third['external_order_number'] = 'DEMO-1002';
        $third['sku'] = 'A1';
        $third['quantity'] = '3';
        $third['unit_price'] = '29.00';
        foreach ([$first, $second, $third] as $index => $entry) {
            foreach (array_values($entry) as $j => $value) {
                if ($value !== '') $example->setCellValueExplicit([$j+1, $index+2], (string) $value, DataType::TYPE_STRING);
            }
        }
        $example->getStyle('A1:O1')->getFont()->setBold(true);
        $example->getStyle('A1:O1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF94A3B8');
        $example->getColumnDimension('A')->setWidth(26);
        foreach (range('B', 'O') as $letter) $example->getColumnDimension($letter)->setWidth(18);
        $help->getColumnDimension('A')->setWidth(35);
        $help->getColumnDimension('B')->setWidth(100);
        $help->getStyle('A1:B1')->getFont()->setBold(true)->setSize(13);
        $book->setActiveSheetIndex(0);
        $tmp = tempnam(sys_get_temp_dir(), 'byl-oms-import-');
        try {
            (new Xlsx($book))->save($tmp);
            return (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
            $book->disconnectWorksheets();
        }
    }

    /** @return array{count:int,row_count:int,valid_count:int,errors:list<array{row:int,message:string}>,preview:list<array<string,mixed>>,token:string|null,orders:list<array<string,mixed>>} */
    public function inspect(UploadedFile $file, Authenticatable $actor): array
    {
        try {
            $parsed = $this->reader->read($file);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['file' => '无法读取导入模板：'.$e->getMessage()]);
        }
        if ($parsed['headers'] !== self::FIELDS) {
            throw ValidationException::withMessages(['file' => '表头与最新的一行一 SKU 模板不一致。请重新下载模板。']);
        }

        $rawRows = collect($parsed['rows'])->filter(fn (array $r): bool =>
            collect($r['cells'])->contains(fn ($cell): bool => trim((string) $cell) !== '')
        )->values()->all();
        $rowCount = count($rawRows);
        if ($rowCount < 1 || $rowCount > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => '一次可导入 1–'.self::MAX_ROWS.' 个 SKU 商品行。']);
        }

        $groups = [];
        $errors = [];
        $allSku = [];
        $rowGroups = [];
        foreach ($rawRows as $raw) {
            $row = (int) $raw['row'];
            if (count($raw['cells']) > count(self::FIELDS)
                && collect(array_slice($raw['cells'], count(self::FIELDS)))
                    ->contains(fn ($cell): bool => trim((string) $cell) !== '')) {
                $errors[] = ['row' => $row, 'message' => '模板最后一列之后不能有额外字段'];
            }
            $cells = array_replace(array_fill(0, count(self::FIELDS), ''), $raw['cells']);
            $entry = array_combine(self::FIELDS,
                array_map(fn ($cell): string => trim((string) $cell), array_slice($cells, 0, count(self::FIELDS))));
            $number = $entry['external_order_number'];
            if ($number === '') {
                $errors[] = ['row' => $row, 'message' => '每个 SKU 行必须填写 external_order_number，用于归属同一张订单'];
                continue;
            }
            if (mb_strlen($number) > 128) $errors[] = ['row' => $row, 'message' => '来源订单号最多 128 字符'];
            $key = mb_strtolower($number);
            $rowGroups[$row] = $key;
            if (! isset($groups[$key])) {
                foreach (self::ORDER_REQUIRED as $field) {
                    if ($entry[$field] === '') $errors[] = ['row' => $row, 'message' => '该订单首行 '.$field.' 必填'];
                }
                $groups[$key] = [
                    'row' => $row, 'external_order_number' => $number,
                    'items' => [], 'item_rows' => [], 'order_fields' => array_intersect_key($entry, array_flip(self::ORDER_FIELDS)),
                ];
            } else {
                foreach (self::ORDER_FIELDS as $field) {
                    $subsequent = $entry[$field];
                    $initial = $groups[$key]['order_fields'][$field];
                    if ($subsequent !== '' && $subsequent !== $initial) {
                        $errors[] = ['row' => $row,
                            'message' => '同一订单 '.$number.' 的 '.$field.' 与第 '.$groups[$key]['row'].' 行不一致；后续行可留空继承'];
                    }
                }
            }

            foreach (self::LINE_REQUIRED as $field) {
                if ($entry[$field] === '') $errors[] = ['row' => $row, 'message' => $field.' 为每个 SKU 行必填'];
            }
            if (mb_strlen($entry['sku']) > 128) $errors[] = ['row' => $row, 'message' => 'SKU 最多 128 字符'];
            if (! preg_match('/^[1-9][0-9]{0,4}$/', $entry['quantity']) || (int) $entry['quantity'] > 99999) {
                $errors[] = ['row' => $row, 'message' => 'quantity 必须为 1–99999 的整数'];
            }
            if (! preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/', $entry['unit_price'])) {
                $errors[] = ['row' => $row, 'message' => 'unit_price 必须是最多两位小数的非负金额'];
            }
            $allSku[$entry['sku']] = true;
            $groups[$key]['items'][] = [
                'row' => $row, 'sku' => $entry['sku'],
                'quantity' => (int) $entry['quantity'], 'unit_price' => $entry['unit_price'],
            ];
            $groups[$key]['item_rows'][] = $row;
            if (count($groups[$key]['items']) > self::MAX_ITEMS_PER_ORDER) {
                $errors[] = ['row' => $row, 'message' => '每张订单最多 '.self::MAX_ITEMS_PER_ORDER.' 个 SKU 商品行'];
            }
        }
        if (count($groups) > self::MAX_ORDERS) {
            throw ValidationException::withMessages(['file' => '单次最多 '.self::MAX_ORDERS.' 张不同来源订单号的订单。']);
        }
        $orders = [];
        foreach ($groups as $key => $group) {
            $fields = $group['order_fields'];
            $row = $group['row'];
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $fields['order_date']);
            if (! $date || $date->format('Y-m-d') !== $fields['order_date']) {
                $errors[] = ['row' => $row, 'message' => 'order_date 必须为 YYYY-MM-DD'];
            }
            foreach (['recipient_name'=>255,'phone'=>64,'country'=>128,'province_state'=>128,'city'=>128,'address_line_1'=>255,'address_line_2'=>255,'postal_code'=>32,'order_note'=>5000] as $field=>$length) {
                if (mb_strlen($fields[$field]) > $length) {
                    $errors[] = ['row' => $row, 'message' => $field.' 超过最大长度 '.$length];
                }
            }
            $currency = $fields['currency'] ?: 'USD';
            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                $errors[] = ['row' => $row, 'message' => 'currency 必须为三位大写货币代码'];
            }
            $orders[] = [
                'row' => $row, 'external_order_number' => $group['external_order_number'],
                'order_date' => $fields['order_date'], 'recipient_name' => $fields['recipient_name'],
                'phone' => $fields['phone'], 'country' => $fields['country'],
                'province_state' => $fields['province_state'], 'city' => $fields['city'],
                'address_line_1' => $fields['address_line_1'], 'address_line_2' => $fields['address_line_2'],
                'postal_code' => $fields['postal_code'], 'currency' => $currency,
                'order_note' => $fields['order_note'], 'items' => $group['items'],
            ];
        }
        $existing = Order::withTrashed()
            ->whereIn(DB::raw('LOWER(external_order_number)'), array_keys($groups))
            ->pluck('external_order_number')
            ->map(fn ($number): string => mb_strtolower((string) $number))->all();
        foreach ($orders as $order) {
            if (in_array(mb_strtolower($order['external_order_number']), $existing, true)) {
                $errors[] = ['row' => $order['row'], 'message' => '来源订单号已经存在于 ERP：'.$order['external_order_number']];
            }
        }

        $products = Product::query()->whereIn('sku', array_keys($allSku))
            ->get(['id','sku','name'])->groupBy('sku');
        foreach ($orders as &$order) {
            foreach ($order['items'] as &$item) {
                $matching = $products->get($item['sku']);
                if (! $matching || $matching->count() !== 1) {
                    $errors[] = ['row' => $item['row'], 'message' => 'SKU '.$item['sku'].' 在 ERP 产品库'.($matching ? '匹配多个产品' : '不存在')];
                } else {
                    $item['product_id'] = (int) $matching->first()->id;
                    $item['product_summary'] = (string) ($matching->first()->name ?: $item['sku']);
                }
            }
            unset($item);
        }
        unset($order);

        $preview = [];
        foreach ($orders as $order) {
            $totalQuantity = array_sum(array_column($order['items'], 'quantity'));
            foreach ($order['items'] as $index => $item) {
                $preview[] = [
                    'row' => $item['row'],
                    'order_number' => $order['external_order_number'],
                    'customer' => $index === 0 ? $order['recipient_name'] : '',
                    'country' => $index === 0 ? $order['country'] : '',
                    'sku_detail' => $item['sku'].' × '.$item['quantity'],
                    'total_quantity' => $item['quantity'],
                    'order_total_quantity' => $totalQuantity,
                ];
            }
        }
        $invalidGroups = [];
        foreach ($errors as $problem) {
            $key = $rowGroups[$problem['row']] ?? null;
            if ($key !== null) $invalidGroups[$key] = true;
        }
        $token = $errors === [] ? Crypt::encryptString(json_encode([
            'sha' => hash_file('sha256', $file->getRealPath()),
            'actor' => (string) $actor->getAuthIdentifier(),
            'expires' => now()->addMinutes(15)->timestamp,
            'count' => count($orders), 'row_count' => $rowCount,
        ], JSON_THROW_ON_ERROR)) : null;

        return [
            'count' => count($orders),
            'row_count' => $rowCount,
            'valid_count' => count($orders) - count($invalidGroups),
            'errors' => $errors, 'preview' => $preview, 'token' => $token, 'orders' => $orders,
        ];
    }

    /** @return array{created:int,orders:list<array{id:int,erp_order_id:string|null}>} */
    public function commit(UploadedFile $file, string $token, Authenticatable $actor): array
    {
        try {
            $proof = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ValidationException::withMessages(['token' => '预检凭证无效，请重新上传预检。']);
        }
        if (! is_array($proof)
            || ($proof['actor'] ?? '') !== (string) $actor->getAuthIdentifier()
            || ($proof['expires'] ?? 0) < now()->timestamp
            || ! hash_equals((string) ($proof['sha'] ?? ''), (string) hash_file('sha256', $file->getRealPath()))) {
            throw ValidationException::withMessages(['token' => '文件已变化或预检已过期，请重新预检。']);
        }
        return DB::transaction(function () use ($file, $proof, $actor): array {
            $data = $this->inspect($file, $actor);
            if ($data['errors'] !== [] || $data['count'] !== (int) $proof['count']
                || $data['row_count'] !== (int) ($proof['row_count'] ?? 0)) {
                throw ValidationException::withMessages(['file' => '文件数据存在问题或已被导入，请重新预检。']);
            }
            // Exactly-once claim: the unique file digest and normalized source numbers
            // are inserted inside the SAME transaction as OrderService::create() + audit.
            // They roll back together if any product, address or order creation fails.
            $inserted = DB::table('oms_order_import_batches')->insertOrIgnore([
                'file_sha256' => $proof['sha'], 'actor_id' => $actor->getAuthIdentifier(),
                'order_count' => count($data['orders']), 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($inserted !== 1) {
                throw ValidationException::withMessages(['file' => '该文件已完成过批量创建，请勿重复提交。']);
            }
            $batchId = (int) DB::table('oms_order_import_batches')->where('file_sha256', $proof['sha'])->value('id');
            $created = [];
            foreach ($data['orders'] as $order) {
                $normalized = mb_strtolower(trim((string) $order['external_order_number']));
                $claimed = DB::table('oms_order_import_claims')->insertOrIgnore([
                    'batch_id' => $batchId,
                    'source_number_normalized' => $normalized,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($claimed !== 1) {
                    throw ValidationException::withMessages(['file' => '来源订单号已被其他批次导入：'.$order['external_order_number']]);
                }
                $items = array_map(function (array $item): array {
                    unset($item['row']);
                    return $item;
                }, $order['items']);
                unset($order['items'], $order['row']);
                $order['order_kind'] = OrderKind::Customer->value;
                $order['source_type'] = OrderSourceType::Manual->value;
                $order['owner_id'] = $actor->getAuthIdentifier();
                $result = $this->orders->create($order, $items, $actor);
                DB::table('oms_order_import_claims')->where('source_number_normalized', $normalized)
                    ->update(['order_id' => $result->id, 'updated_at' => now()]);
                $created[] = ['id' => (int) $result->id, 'erp_order_id' => $result->erp_order_id];
            }
            return ['created' => count($created), 'orders' => $created];
        });
    }
}
