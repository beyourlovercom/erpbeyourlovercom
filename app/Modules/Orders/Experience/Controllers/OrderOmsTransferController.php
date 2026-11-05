<?php

namespace App\Modules\Orders\Experience\Controllers;

use App\Modules\Logistics\Application\OverseaFiles\ProviderXlsxWriter;
use App\Modules\Orders\Application\KolOrderAccessScope;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderOmsBatchImport;
use App\Modules\Orders\Application\OrderOmsAvailability;
use App\Modules\Orders\Application\OrderService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class OrderOmsTransferController
{
    public function __construct(
        private readonly OrderOmsBatchImport $import,
        private readonly OrderService $orders,
        private readonly ProviderXlsxWriter $writer,
    ) {}

    private function pilot(Request $request, bool $write = false): void
    {
        abort_unless($write
            ? OrderOmsAvailability::import($request->user())
            : OrderOmsAvailability::export($request->user()), 404);
    }

    public function template(Request $request): Response
    {
        $this->pilot($request, true);
        KolOrderAccessScope::authorizeCreate($request->user(), OrderKind::Customer);

        return response($this->import->template(), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="BYL-ERP-Order-Import-Template-V1.xlsx"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $this->pilot($request, true);
        KolOrderAccessScope::authorizeCreate($request->user(), OrderKind::Customer);
        try {
            $payload = $request->validate(['file' => ['required', 'file', 'extensions:xlsx', 'max:5120']]);
            $result = $this->import->inspect($payload['file'], $request->user());
            unset($result['orders']);

            return response()->json($result);
        } catch (ValidationException $e) {
            return response()->json(['message' => '导入预检失败', 'errors' => $e->errors()], 422);
        }
    }

    public function confirm(Request $request): JsonResponse
    {
        $this->pilot($request, true);
        KolOrderAccessScope::authorizeCreate($request->user(), OrderKind::Customer);
        try {
            $payload = $request->validate([
                'file' => ['required', 'file', 'extensions:xlsx', 'max:5120'],
                'token' => ['required', 'string', 'max:8000'],
                'confirm' => ['required', 'accepted'],
            ]);

            return response()->json($this->import->commit($payload['file'], $payload['token'], $request->user()));
        } catch (ValidationException $e) {
            return response()->json(['message' => '批量创建失败，请重新预检', 'errors' => $e->errors()], 422);
        }
    }

    public function export(Request $request): Response
    {
        $this->pilot($request);

        $filters = $request->only(['search', 'source_type', 'order_status', 'workflow_status', 'view']);
        $filters = KolOrderAccessScope::scopeFilters($request->user(), $filters);
        $sortFields = ['order_number', 'date', 'source', 'status'];
        $filters['oms_sort'] = in_array($request->query('sort'), $sortFields, true) ? $request->query('sort') : 'date';
        $filters['oms_direction'] = $request->query('direction') === 'asc' ? 'asc' : 'desc';
        $filters['oms_numeric_contains'] = true;
        $columns = [];
        foreach ([
            'order_number', 'sku', 'quantity', 'product_names', 'source', 'date',
            'recipient', 'country', 'warehouse', 'status', 'tracking', 'owner',
            'city', 'postal_code', 'carrier', 'external_number',
        ] as $key) {
            $input = $request->query('grid_'.$key);
            if (is_string($input) && trim($input) !== '') {
                $columns['grid_'.$key] = mb_substr(trim($input), 0, 80);
            }
        }
        $filters['oms_grid_columns'] = $columns;
        $headers = [
            'ERP订单号', '来源订单号', '下单日期', '来源', 'SKU', 'SKU数量', '订单总件数', '整单SKU×数量',
            '产品名称', '商品行金额', '币种', '收件人', '电话', '国家', '州/省', '城市', '邮编',
            '地址第1行', '地址第2行', '工作状态', '仓库', '物流商', '渠道', '物流单号', '发货时间', '负责人', '订单备注',
        ];
        if ($request->query('format') === 'csv') {
            return response()->streamDownload(function () use ($filters, $headers, $columns): void {
                $handle = fopen('php://output', 'w');
                fwrite($handle, "\xEF\xBB\xBF");
                fputcsv($handle, $headers);
                foreach ($this->orders->exportBatched($filters) as $order) {
                    foreach ($this->exportRows($order, $columns) as $raw) {
                        $line = array_map(function ($value): string {
                            $text = (string) ($value ?? '');
                            // Neutralize Excel formula injection in untrusted text cells.
                            return preg_match('/^\s*[=+@-]/u', $text) ? "'".$text : $text;
                        }, $raw);
                        fputcsv($handle, $line);
                    }
                }
                fclose($handle);
            }, 'BYL-ERP-Orders-'.now()->format('Ymd').'.csv', [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
            ]);
        }

        $rows = [];
        $orderCount = 0;
        foreach ($this->orders->exportBatched($filters) as $order) {
            if (++$orderCount > 2000) {
                throw ValidationException::withMessages(['export' => '单次 XLSX 导出最多 2000 张订单，请缩小筛选范围；更多订单请使用 CSV 导出。']);
            }
            foreach ($this->exportRows($order, $columns) as $row) {
                if (count($rows) >= 5000) {
                    throw ValidationException::withMessages(['export' => 'XLSX 单次最多 5000 个 SKU 商品行，请缩小筛选范围或使用 CSV 导出。']);
                }
                $rows[] = $row;
            }
        }

        return response($this->writer->write('ERP订单', $headers, $rows), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="BYL-ERP-Orders-'.now()->format('Ymd').'.xlsx"',
            'Cache-Control' => 'no-store, private',
        ]);
    }


    /** @return list<list<mixed>> */
    private function exportRows(Order $order, array $lineFilters = []): array
    {
        $allItems = $order->items->sortBy('id')->values();
        $all = $allItems->map(fn ($item) => $item->sku.' × '.$item->quantity)->implode('; ');
        $totalUnits = $allItems->sum('quantity');
        // Match the same SKU-line filters shown by the grid, while retaining
        // the original order total in each output row.
        $sku = mb_strtolower((string) ($lineFilters['grid_sku'] ?? ''));
        $name = mb_strtolower((string) ($lineFilters['grid_product_names'] ?? ''));
        $qtyText = (string) ($lineFilters['grid_quantity'] ?? '');
        $items = $allItems->filter(function ($item) use ($sku, $name, $qtyText): bool {
            if ($sku !== '' && ! str_contains(mb_strtolower((string) $item->sku), $sku)) return false;
            if ($name !== '' && ! str_contains(mb_strtolower((string) $item->product_summary), $name)) return false;
            if ($qtyText !== '' && (int) $item->quantity !== (int) $qtyText) return false;

            return true;
        })->values();
        $rows = [];
        $emptyHistoric = $allItems->isEmpty() && $sku === '' && $name === '' && $qtyText === '';
        foreach ($emptyHistoric ? [null] : $items as $item) {
            $rows[] = [
                $order->erp_order_id, $order->external_order_number, $order->order_date?->toDateString(),
                $order->source_type->value,
                $item?->sku, $item?->quantity, $items->isEmpty() ? null : $totalUnits,
                $all, $item?->product_summary,
                $item?->line_revenue, $order->currency,
                $order->address?->recipient_name, $order->address?->phone,
                $order->address?->country, $order->address?->province_state, $order->address?->city,
                $order->address?->postal_code, $order->address?->address_line_1, $order->address?->address_line_2,
                $order->workflow_status ?: $order->order_status->value,
                $order->shipment?->warehouse?->name, $order->shipment?->carrier,
                $order->shipment?->service, $order->shipment?->tracking_number,
                $order->shipment?->shipped_at?->toDateTimeString(), $order->owner?->name, $order->order_note,
            ];
        }

        return $rows;
    }
}
