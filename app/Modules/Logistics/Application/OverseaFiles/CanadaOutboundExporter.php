<?php

namespace App\Modules\Logistics\Application\OverseaFiles;

use App\Modules\Logistics\Infrastructure\Models\Shipment;

final class CanadaOutboundExporter
{
    private const HEADERS = [
        '客户参考号', '仓库代码', '物流产品', '是否上传发货标签', '物流单号', '发货标签尺寸', '发货标签URL', '发票尺寸', '发票URL',
        '货品申明价值', '投保币种', '退件服务', '签名服务', '销售平台', '平台交易号', '卖家ID', '国家', '州/省', '城市', '区县',
        '街道', '邮编', '收件人名', '电话', 'Email', '公司名称', '门牌号', '身份证', '收件人VAT No.', '收件人EORI No.', '备注',
        '发件人VAT No.', '发件人EORI No.', 'IOSS', '申报总价值', '申报币种', 'HS Code', '出口商公司', '出口商公司地址',
        'SKU1', 'QTY1', 'Batch No.1', 'Inventory Quality1', 'eBay Item ID1', 'eBay Transaction ID1',
        'SKU2', 'QTY2', 'Batch No.2', 'Inventory Quality2', 'eBay Item ID2', 'eBay Transaction ID2',
        'SKU3', 'QTY3', 'Batch No.3', 'Inventory Quality3', 'eBay Item ID3', 'eBay Transaction ID3',
        'SKU4', 'QTY4', 'Batch No.4', 'Inventory Quality4', 'eBay Item ID4', 'eBay Transaction ID4',
        'SKU5', 'QTY5', 'Batch No.5', 'Inventory Quality5', 'eBay Item ID5', 'eBay Transaction ID5',
    ];

    public function __construct(
        private readonly ProviderXlsxWriter $xlsx,
        private readonly WarehouseOrderReference $references,
    ) {}

    /** @param iterable<Shipment> $shipments */
    public function export(iterable $shipments): string
    {
        $rows = [];
        foreach ($shipments as $shipment) {
            $rows[] = $this->row($shipment);
        }

        return $this->xlsx->write('OutboundConsignment', self::HEADERS, $rows);
    }

    /** @return list<mixed> */
    private function row(Shipment $shipment): array
    {
        $order = $shipment->order;
        abort_unless($order && $order->address, 422, '加拿大仓订单缺少收件地址。');
        $items = $order->items->values();
        abort_if($items->isEmpty(), 422, "订单 {$order->erp_order_id} 没有可导出的 SKU。");
        abort_if($items->count() > 5, 422, "订单 {$order->erp_order_id} 超过加拿大模板 5 个 SKU 上限。");

        $a = $order->address;
        $row = array_fill(0, count(self::HEADERS), null);
        $row[0] = $this->references->canada($order);
        $row[1] = 'CA02_多伦多仓';
        $row[2] = 'CAPOST[加拿大邮政多伦多]';
        $row[16] = $this->required(strtoupper((string) $a->country), '国家', $order->erp_order_id);
        $row[17] = $a->province_state;
        $row[18] = $this->required($a->city, '城市', $order->erp_order_id);
        $row[20] = trim(implode(' ', array_filter([$a->address_line_1, $a->address_line_2])));
        $row[21] = $this->required($a->postal_code, '邮编', $order->erp_order_id);
        $row[22] = $this->required($a->recipient_name, '收件人', $order->erp_order_id);
        $row[23] = $a->phone;
        $row[30] = $order->order_note ?: $shipment->logistics_note;

        foreach ($items as $index => $item) {
            $sku = trim((string) $item->sku);
            abort_if($sku === '', 422, "订单 {$order->erp_order_id} 存在空 SKU。");
            $base = 39 + ($index * 6);
            $row[$base] = $sku;
            $row[$base + 1] = (int) $item->quantity;
            $row[$base + 3] = 'G  良好';
        }

        return $row;
    }

    private function required(mixed $value, string $label, string $order): string
    {
        $value = trim((string) ($value ?? ''));
        abort_if($value === '', 422, "订单 {$order} 缺少{$label}，不能导出加拿大仓文件。");

        return $value;
    }
}
