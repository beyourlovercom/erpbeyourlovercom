<?php

namespace App\Modules\Logistics\Application\Domestic;

use App\Modules\Logistics\Application\OverseaFiles\WarehouseOrderReference;
use App\Modules\Orders\Infrastructure\Models\Order;
use DomainException;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Illuminate\Validation\ValidationException;

final class SpeedaOutboundExporter
{
    private const TEMPLATE = 'resources/logistics/templates/speeda-outbound-v1.xlsx';
    // Official 2026-10-26 4PX template: Y SKU, Z manufacturer barcode, AA blank.
    private const EUROPE = ['AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU',
        'IE','IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE',
        'GB','UK','NO','CH','IS','LI','AD','MC','SM','VA','AL','BA','ME','MK','RS','MD','UA','TR'];

    public function __construct(
        private readonly WarehouseOrderReference $references,
        private readonly ChinaSmallPacketBarcode69Reader $barcodes,
    ) {}

    /** @return list<string> */
    public function blockers(Order $order, string $providerLabel = '速达'): array
    {
        $order->loadMissing(['address', 'items', 'externalReferences']);
        $address = $order->address;
        $missing = [];

        try {
            $reference = trim($this->references->warehouse($order));
        } catch (DomainException) {
            $reference = '';
        }

        $checks = [
            '客户单号' => $reference,
            '目的国家' => trim((string) $address?->country),
            '收件人姓名' => trim((string) $address?->recipient_name),
            '城市' => trim((string) $address?->city),
            '联系地址' => trim(implode(' ', array_filter([
                trim((string) $address?->address_line_1),
                trim((string) $address?->address_line_2),
            ]))),
            '收件人电话' => trim((string) $address?->phone),
            '收件人邮编' => trim((string) $address?->postal_code),
        ];

        foreach ($checks as $label => $value) {
            if ($value === '') {
                $missing[] = $label;
            }
        }
        if ((int) $order->items->sum('quantity') <= 0) {
            $missing[] = '申报品数量1';
        }

        if ($providerLabel === '速达' && $this->isEurope($address?->country)) {
            try {
                $this->oneBarcodeForOrder($order);
            } catch (DomainException $e) {
                $missing[] = $e->getMessage();
            }
        }

        return $missing;
    }

    /**
     * Business-continuity export: invalid orders are isolated instead of blocking
     * the valid daily batch.
     *
     * @param  Collection<int,Order>  $orders
     * @return array{bytes:string,exported_count:int,blocked:list<array{order_id:int,erp_order_id:?string,reasons:list<string>}>}
     */
    public function exportReady(Collection $orders, string $providerLabel = '速达'): array
    {
        $ready = collect();
        $blocked = [];

        foreach ($orders as $order) {
            $reasons = $this->blockers($order, $providerLabel);
            if ($reasons === []) {
                $ready->push($order);

                continue;
            }

            $blocked[] = [
                'order_id' => (int) $order->getKey(),
                'erp_order_id' => $order->erp_order_id,
                'reasons' => $reasons,
            ];
        }

        if ($ready->isEmpty()) {
            $summary = collect($blocked)
                ->take(5)
                ->map(fn (array $row): string => ($row['erp_order_id'] ?: '#'.$row['order_id']).'：'.implode('、', $row['reasons']))
                ->implode('；');
            throw new DomainException('没有可安全导出的'.$providerLabel.'订单。'.$summary);
        }

        return [
            'bytes' => $this->export($ready, $providerLabel),
            'exported_count' => $ready->count(),
            'blocked' => $blocked,
        ];
    }

    /** @param Collection<int,Order> $orders */
    public function export(Collection $orders, string $providerLabel = '速达'): string
    {
        if ($orders->isEmpty()) {
            throw new DomainException('当前没有可导出的'.$providerLabel.'待发货订单。');
        }

        $path = base_path(self::TEMPLATE);
        if (! is_file($path)) {
            throw new RuntimeException('国内承运商 24 列基础模板未安装。');
        }

        $book = IOFactory::load($path);
        $sheet = $book->getSheetByName('标准上传格式');
        if (! $sheet) {
            throw new RuntimeException('4PX / 纬狮共享模板缺少“标准上传格式”工作表。');
        }

        $speeda = $providerLabel === '速达';
        if ($speeda) {
            $this->enableSpeeda27Columns($sheet);
        }
        $lastColumn = $speeda ? 'AA' : 'X';
        $countryMap = $this->countryMap($book);
        $highest = $sheet->getHighestDataRow();
        if ($highest > 2) {
            $sheet->removeRow(3, $highest - 2);
        }

        $orders = $orders->values();
        foreach ($orders as $index => $order) {
            $row = $index + 2;
            if ($row > 2) {
                $sheet->insertNewRowBefore($row, 1);
                $sheet->duplicateStyle($sheet->getStyle('A2:'.$lastColumn.'2'), "A{$row}:{$lastColumn}{$row}");
                for ($colIndex = 1; $colIndex <= ($speeda ? 27 : 24); $colIndex++) {
                    $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex);
                    $sheet->setCellValue("{$column}{$row}", $sheet->getCell("{$column}2")->getValue());
                }
            }

            $order->loadMissing(['address', 'items.product', 'externalReferences']);
            $address = $order->address;
            $countryCode = strtoupper(trim((string) $address?->country));
            $country = $countryMap[$countryCode] ?? $countryCode;
            $quantity = max(1, (int) $order->items->sum('quantity'));
            $taxId = $this->taxId($order);

            // Preserve existing A:X mappings; 4PX-specific Y:AA fields are applied below.
            $sheet->setCellValueExplicit("A{$row}", $this->references->warehouse($order), DataType::TYPE_STRING);
            $sheet->setCellValue("B{$row}", '');
            $sheet->setCellValue("C{$row}", '');
            $sheet->setCellValueExplicit("D{$row}", $country, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("E{$row}", trim((string) $address?->recipient_name), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("F{$row}", trim((string) $address?->province_state), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("G{$row}", trim((string) $address?->city), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("H{$row}", trim(implode(' ', array_filter([
                trim((string) $address?->address_line_1),
                trim((string) $address?->address_line_2),
            ]))), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("I{$row}", trim((string) $address?->phone), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("J{$row}", trim((string) $address?->email), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("K{$row}", trim((string) $address?->postal_code), DataType::TYPE_STRING);

            // Explicit owner freeze: these fields must be blank rather than guessed.
            $sheet->setCellValue("M{$row}", '');
            $sheet->setCellValue("N{$row}", '');
            $sheet->setCellValue("P{$row}", '');
            $sheet->setCellValue("Q{$row}", $quantity);

            if ($taxId !== null) {
                $sheet->setCellValueExplicit("W{$row}", 'IOSS', DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("X{$row}", $taxId, DataType::TYPE_STRING);
            } else {
                $sheet->setCellValue("W{$row}", '');
                $sheet->setCellValue("X{$row}", '');
            }

            if ($speeda) {
                $sku = '';
                $barcode = '';
                if ($this->isEurope($countryCode)) {
                    [$sku, $barcode] = $this->oneBarcodeForOrder($order);
                }
                $sheet->setCellValueExplicit("Y{$row}", $sku, DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("Z{$row}", $barcode, DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("AA{$row}", '', DataType::TYPE_STRING);
            }

            $this->assertRequired($order, $row, [
                'A' => '客户单号',
                'D' => '目的国家',
                'E' => '收件人姓名',
                'G' => '城市',
                'H' => '联系地址',
                'I' => '收件人电话',
                'K' => '收件人邮编',
                'Q' => '申报品数量1',
            ], $sheet, $providerLabel);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'domestic-provider-outbound-');
        if ($tmp === false) {
            throw new RuntimeException('无法创建国内承运商导出临时文件。');
        }
        try {
            (new Xlsx($book))->save($tmp);
            $bytes = file_get_contents($tmp);
        } finally {
            $book->disconnectWorksheets();
            @unlink($tmp);
        }
        if (! is_string($bytes)) {
            throw new RuntimeException('无法读取国内承运商导出文件。');
        }

        return $bytes;
    }

    private function isEurope(?string $country): bool
    {
        return in_array(strtoupper(trim((string) $country)), self::EUROPE, true);
    }

    /** @return array{0:string,1:string} */
    private function oneBarcodeForOrder(Order $order): array
    {
        try {
            $rows = $this->barcodes->forOrder($order);
        } catch (ValidationException $e) {
            throw new DomainException('欧洲速达订单缺少经过SKU核实的69条形码', previous: $e);
        }
        if ($rows === []) {
            throw new DomainException('欧洲速达订单缺少商品SKU');
        }
        // One 4PX Y/Z pair represents ONE SKU, not an arbitrary multi-SKU parcel.
        $pairs = array_unique(array_map(fn (array $row) => $row['sku'].'|'.$row['barcode_69'], $rows));
        if (count($pairs) !== 1) {
            throw new DomainException('欧洲速达订单包含多个SKU，无法安全填写单组Y/Z条码');
        }
        return [(string) $rows[0]['sku'], (string) $rows[0]['barcode_69']];
    }

    private function enableSpeeda27Columns($sheet): void
    {
        $sheet->duplicateStyle($sheet->getStyle('X1'), 'Y1:AA1');
        $sheet->duplicateStyle($sheet->getStyle('X2'), 'Y2:AA2');
        foreach (['Y' => '商家产品识别码', 'Z' => '制造商产品识别码', 'AA' => '国际标准商品编码'] as $column => $title) {
            $sheet->setCellValueExplicit($column.'1', $title, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit($column.'2', '', DataType::TYPE_STRING);
        }
        $sheet->getColumnDimension('Y')->setWidth(22);
        $sheet->getColumnDimension('Z')->setWidth(25);
        $sheet->getColumnDimension('AA')->setWidth(24);
    }

    /** @return array<string,string> */
    private function countryMap(Spreadsheet $book): array
    {
        $sheet = $book->getSheetByName('国家对照表');
        if (! $sheet) {
            return [];
        }
        $map = [];
        for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
            $code = strtoupper(trim((string) $sheet->getCell("A{$row}")->getFormattedValue()));
            $english = trim((string) $sheet->getCell("C{$row}")->getFormattedValue());
            if ($code !== '' && $english !== '') {
                $map[$code] = $english;
            }
        }

        return $map;
    }

    private function taxId(Order $order): ?string
    {
        // No tax-id evidence is currently persisted by the Woo inbound contract.
        // Support an explicit future evidence reference without guessing from country/order text.
        $reference = $order->externalReferences
            ->first(fn ($row) => in_array(strtolower((string) $row->provider), ['ioss', 'woocommerce_ioss'], true))
            ?->reference;
        $value = strtoupper(trim((string) $reference));

        return preg_match('/\AIM[A-Z0-9]{6,32}\z/', $value) === 1 ? $value : null;
    }

    /** @param array<string,string> $required */
    private function assertRequired(Order $order, int $row, array $required, $sheet, string $providerLabel): void
    {
        $missing = [];
        foreach ($required as $column => $label) {
            if (trim((string) $sheet->getCell("{$column}{$row}")->getFormattedValue()) === '') {
                $missing[] = $label;
            }
        }
        if ($missing !== []) {
            throw new DomainException("订单 {$order->erp_order_id} 缺少{$providerLabel}必填字段：".implode('、', $missing));
        }
    }
}
