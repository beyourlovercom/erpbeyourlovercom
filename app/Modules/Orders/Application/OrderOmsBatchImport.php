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
    public const MAX_ITEMS_PER_ORDER = 5;
    public const FIELDS = [
        'external_order_number', 'order_date', 'recipient_name', 'phone', 'country',
        'province_state', 'city', 'address_line_1', 'address_line_2', 'postal_code',
        'currency', 'order_note',
        'sku_1', 'quantity_1', 'unit_price_1',
        'sku_2', 'quantity_2', 'unit_price_2',
        'sku_3', 'quantity_3', 'unit_price_3',
        'sku_4', 'quantity_4', 'unit_price_4',
        'sku_5', 'quantity_5', 'unit_price_5',
    ];

    private const REQUIRED = [
        'external_order_number', 'order_date', 'recipient_name', 'country', 'province_state',
        'city', 'address_line_1', 'sku_1', 'quantity_1', 'unit_price_1',
    ];

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
        foreach (self::REQUIRED as $key) {
            $index = array_search($key, self::FIELDS, true);
            $cell = $sheet->getCell([$index + 1, 1]);
            $cell->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFBD55');
            $cell->getStyle()->getFont()->getColor()->setARGB('FF172B4D');
        }
        // Preserve leading zeros in external order numbers and SKU strings.
        foreach (['A', 'B', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'M', 'P', 'S', 'V', 'Y'] as $letter) {
            $sheet->getStyle($letter.'2:'.$letter.'202')->getNumberFormat()->setFormatCode('@');
        }
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:'.$lastCol.'1');
        $help = $book->createSheet();
        $help->setTitle('使用说明');
        $tips = [
            ['BYL ERP 批量新建客户订单 V1', ''],
            ['一行一个订单', '一行最多 5 个 SKU。多商品横向填写 sku_2 / quantity_2 / unit_price_2 等字段'],
            ['黄色表头', '必填；灰色表头可留空。不要删除、修改、重排第一行字段名称'],
            ['external_order_number', '必须是唯一的手动订单来源编号；不可与现有订单或文件内其他订单重复'],
            ['order_date', '填写 YYYY-MM-DD，例如 2026-11-05，勿使用 Excel 自动日期格式'],
            ['SKU', '必须是 ERP 产品库中真实存在且唯一的 SKU；每个商品需要 SKU、数量、单价'],
            ['quantity_1~5', '必须为 1–99999 的正整数'],
            ['unit_price_1~5', '订单商品单价，0 或正数，最多两位小数；请勿填写货本或仓储成本'],
            ['country', '建议使用两位 ISO 国家代码，例如 US、DE、CA'],
            ['recipient_name / province_state / city / address_line_1', '批量建单要求完整收件信息'],
            ['currency', '默认为 USD，支持三位大写货币代码'],
            ['批次限制', '最多 200 个订单，最多 5 个商品行/订单，XLSX 文件上限 5MB'],
            ['导入方式', '选择文件 → 服务端预检 → 检查错误 → 确认创建；预检不会创建任何订单'],
            ['安全', '仅创建 ERP 手动客户订单，不触发 WooCommerce 同步、仓库分配或自动发货'],
        ];
        foreach ($tips as $n => [$key, $value]) {
            $help->setCellValue([1, $n + 1], $key);
            $help->setCellValue([2, $n + 1], $value);
        }
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

    /** @return array{count:int,valid_count:int,errors:list<array{row:int,message:string}>,preview:list<array<string,mixed>>,token:string|null,orders:list<array<string,mixed>>} */
    public function inspect(UploadedFile $file, Authenticatable $actor): array
    {
        try {
            $parsed = $this->reader->read($file);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['file' => '无法读取导入模板：'.$e->getMessage()]);
        }
        if ($parsed['headers'] !== self::FIELDS) {
            throw ValidationException::withMessages(['file' => '模板表头不正确。请下载最新版导入模板，勿修改第一行字段或顺序。']);
        }
        $rawRows = collect($parsed['rows'])->filter(fn (array $r): bool => collect($r['cells'])->contains(fn ($cell) => trim((string) $cell) !== ''))->values()->all();
        if (count($rawRows) < 1 || count($rawRows) > self::MAX_ORDERS) {
            throw ValidationException::withMessages(['file' => '每次必须包含 1–'.self::MAX_ORDERS.' 个订单。']);
        }
        $errors = [];
        $orders = [];
        $numbers = [];
        $skus = [];
        foreach ($rawRows as $raw) {
            $cells = array_replace(array_fill(0, count(self::FIELDS), ''), $raw['cells']);
            $entry = array_combine(self::FIELDS, array_map(fn ($c): string => trim((string) $c), array_slice($cells, 0, count(self::FIELDS))));
            $rowNumber = (int) $raw['row'];
            foreach (self::REQUIRED as $key) {
                if ($entry[$key] === '') $errors[] = ['row' => $rowNumber, 'message' => $key.' 为必填字段'];
            }
            $number = $entry['external_order_number'];
            if (strlen($number) > 128) $errors[] = ['row' => $rowNumber, 'message' => '来源订单号超过 128 字符'];
            if ($number !== '') {
                if (isset($numbers[mb_strtolower($number)])) $errors[] = ['row' => $rowNumber, 'message' => '文件内来源订单号重复：'.$number];
                $numbers[mb_strtolower($number)] = true;
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $entry['order_date']);
            if (! $date || $date->format('Y-m-d') !== $entry['order_date']) {
                $errors[] = ['row' => $rowNumber, 'message' => 'order_date 必须是 YYYY-MM-DD'];
            }
            foreach (['recipient_name'=>255,'phone'=>64,'country'=>128,'province_state'=>128,'city'=>128,'address_line_1'=>255,'address_line_2'=>255,'postal_code'=>32,'order_note'=>5000] as $key=>$max) {
                if (mb_strlen($entry[$key]) > $max) $errors[] = ['row' => $rowNumber, 'message' => $key.' 超过最大长度 '.$max];
            }
            $currency = $entry['currency'] ?: 'USD';
            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                $errors[] = ['row' => $rowNumber, 'message' => 'currency 必须为三位大写代码'];
            }
            $items = [];
            for ($i = 1; $i <= self::MAX_ITEMS_PER_ORDER; $i++) {
                $sku = $entry['sku_'.$i];
                $qty = $entry['quantity_'.$i];
                $price = $entry['unit_price_'.$i];
                if ($sku === '' && $qty === '' && $price === '') continue;
                if ($sku === '' || $qty === '' || $price === '') {
                    $errors[] = ['row' => $rowNumber, 'message' => '第 '.$i.' 个商品需要同时填写 SKU、数量、单价'];
                    continue;
                }
                if (strlen($sku) > 128) $errors[] = ['row' => $rowNumber, 'message' => 'SKU 超过 128 字符'];
                if (! preg_match('/^[1-9][0-9]{0,4}$/', $qty) || (int) $qty > 99999) {
                    $errors[] = ['row' => $rowNumber, 'message' => 'SKU '.$sku.' 的数量必须为 1–99999'];
                }
                if (! preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/', $price)) {
                    $errors[] = ['row' => $rowNumber, 'message' => 'SKU '.$sku.' 单价必须为 0–99999999.99（最多两位小数）'];
                }
                $skus[$sku] = true;
                $items[] = ['sku' => $sku, 'quantity' => (int) $qty, 'unit_price' => $price];
            }
            if ($items === []) $errors[] = ['row' => $rowNumber, 'message' => '至少填写一款商品'];
            $orders[] = ['row' => $rowNumber, 'external_order_number' => $number, 'order_date' => $entry['order_date'], 'currency' => $currency,
                'recipient_name' => $entry['recipient_name'], 'phone' => $entry['phone'], 'country' => $entry['country'], 'province_state' => $entry['province_state'],
                'city' => $entry['city'], 'address_line_1' => $entry['address_line_1'], 'address_line_2' => $entry['address_line_2'],
                'postal_code' => $entry['postal_code'], 'order_note' => $entry['order_note'], 'items' => $items];
        }
        $existing = Order::withTrashed()->whereIn(DB::raw('LOWER(external_order_number)'), array_keys($numbers))
            ->pluck('external_order_number')->map(fn ($value): string => mb_strtolower((string) $value))->all();
        foreach ($orders as $order) {
            if (in_array(mb_strtolower($order['external_order_number']), $existing, true)) $errors[] = ['row' => $order['row'], 'message' => '来源订单号已存在：'.$order['external_order_number']];
        }
        $products = Product::query()->whereIn('sku', array_keys($skus))->get(['id', 'sku', 'name'])->groupBy('sku');
        foreach ($orders as &$order) {
            foreach ($order['items'] as &$item) {
                $match = $products->get($item['sku']);
                if (! $match || $match->count() !== 1) {
                    $errors[] = ['row' => $order['row'], 'message' => 'SKU '.$item['sku'].' 在 ERP 产品库'.($match ? '匹配多条产品' : '不存在')];
                } else {
                    $item['product_id'] = (int) $match->first()->id;
                    $item['product_summary'] = (string) ($match->first()->name ?: $item['sku']);
                }
            }
            unset($item);
        }
        unset($order);
        $preview = array_map(fn (array $o): array => [
            'row' => $o['row'], 'order_number' => $o['external_order_number'],
            'customer' => $o['recipient_name'], 'country' => $o['country'],
            'sku_detail' => implode(' / ', array_map(fn ($i): string => $i['sku'].' × '.$i['quantity'], $o['items'])),
            'total_quantity' => array_sum(array_column($o['items'], 'quantity')),
        ], $orders);
        $token = $errors === [] ? Crypt::encryptString(json_encode([
            'sha' => hash_file('sha256', $file->getRealPath()),
            'actor' => (string) $actor->getAuthIdentifier(), 'expires' => now()->addMinutes(15)->timestamp,
            'count' => count($orders),
        ], JSON_THROW_ON_ERROR)) : null;
        return ['count' => count($orders), 'valid_count' => count($orders) - count(array_unique(array_column($errors, 'row'))),
            'errors' => $errors, 'preview' => $preview, 'token' => $token, 'orders' => $orders];
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
            if ($data['errors'] !== [] || $data['count'] !== (int) $proof['count']) {
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
                $items = $order['items'];
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
