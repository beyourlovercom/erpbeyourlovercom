<?php

namespace App\Modules\Logistics\Application\Domestic;

use App\Modules\Logistics\Application\OverseaFiles\WarehouseOrderReference;
use App\Modules\Logistics\Application\CarrierServiceCatalog;
use App\Modules\Orders\Infrastructure\Models\Order;
use DomainException;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * Download-only report for the existing "今日订单" view.
 * One row per order line item; no tracking/provider import or workflow updates.
 */
final class DomesticTodayOrdersExporter
{
    public function __construct(private readonly WarehouseOrderReference $references, private readonly CarrierServiceCatalog $carriers) {}

    /** @param Collection<int, Order> $orders */
    public function export(Collection $orders): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('今日订单SKU明细');
        $sheet->fromArray([[
            '订单号', 'SKU', '数量', '单价', '物流商',
            '物流商单号', '币种', '商品名称', '发货状态',
        ]]);

        $line = 2;
        foreach ($orders as $order) {
            $order->loadMissing(['items', 'shipment', 'externalReferences']);
            $shipment = $order->shipment;
            try {
                $providerReference = $this->references->warehouse($order);
            } catch (DomainException) {
                $providerReference = '';
            }

            $carrier = (string) $shipment?->carrier;
            $carrierLabel = $this->carriers->carrierDisplayName($carrier);

            // Preserve orders without items in the report without inventing a SKU or price.
            foreach ($order->items->isEmpty() ? [null] : $order->items->sortBy('id') as $item) {
                $values = [
                    1 => (string) $order->erp_order_id,
                    2 => (string) ($item?->sku ?? ''),
                    5 => $carrierLabel,
                    6 => (string) $providerReference,
                    7 => (string) $order->currency,
                    8 => (string) ($item?->product_summary ?? ''),
                    9 => (string) ($shipment?->shipment_status ?? ''),
                ];
                foreach ($values as $column => $value) {
                    // Explicit strings prevent Excel from evaluating untrusted SKU/order numbers as formulas.
                    $sheet->setCellValueExplicit([$column, $line], $value, DataType::TYPE_STRING);
                }
                if ($item !== null) {
                    $sheet->setCellValueExplicit([3, $line], (int) $item->quantity, DataType::TYPE_NUMERIC);
                    if ($item->unit_price !== null) {
                        $sheet->setCellValueExplicit([4, $line], (float) $item->unit_price, DataType::TYPE_NUMERIC);
                    }
                }
                $line++;
            }
        }

        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:I'.max(1, $line - 1));
        $sheet->getStyle('A1:I1')->getFont()->setBold(true);
        if ($line > 2) {
            $sheet->getStyle('D2:D'.($line - 1))
                ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
        }

        foreach (['A' => 28, 'B' => 24, 'C' => 10, 'D' => 12, 'E' => 20,
                  'F' => 28, 'G' => 10, 'H' => 38, 'I' => 16] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'domestic-today-');
        if ($tmp === false) {
            $book->disconnectWorksheets();
            throw new RuntimeException('无法创建今日订单 Excel 文件。');
        }

        try {
            (new Xlsx($book))->save($tmp);
            $bytes = file_get_contents($tmp);
        } finally {
            $book->disconnectWorksheets();
            @unlink($tmp);
        }

        if (! is_string($bytes)) {
            throw new RuntimeException('无法读取今日订单 Excel 文件。');
        }

        return $bytes;
    }
}
