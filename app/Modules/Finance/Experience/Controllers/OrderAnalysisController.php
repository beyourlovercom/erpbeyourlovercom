<?php

namespace App\Modules\Finance\Experience\Controllers;

use App\Modules\Finance\Application\OrderAnalysisService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class OrderAnalysisController
{
    public function __construct(private readonly OrderAnalysisService $analysis) {}

    public function index(Request $request): Response
    {
        $orders = $this->filteredOrders($request);

        return Inertia::render('Finance/OrderAnalysis', [
            'orders' => $orders->map(fn (Order $order): array => [
                'id' => $order->id, 'erp_order_id' => $order->erp_order_id, 'internal_order_number' => $order->internal_order_number,
                'source_type' => $order->source_type->value, 'recipient_name' => $order->address?->recipient_name,
                'summary' => $this->analysis->summary($order),
                'values' => $this->analysis->values($order),
            ])->values()->all(),
            'fields' => $this->analysis->fields(),
            'filters' => $request->only(['search', 'source_type', 'completeness', 'profit_status', 'date_from', 'date_to', 'country', 'sku']),
        ]);
    }

    public function update(Request $request, Order $order): \Illuminate\Http\RedirectResponse
    {
        $fields = array_fill_keys($this->analysis->fields(), ['nullable', 'numeric', 'decimal:0,2', 'min:0']);
        $data = $request->validate([...$fields, 'currency' => ['sometimes', 'string', 'size:3']]);
        $this->analysis->save($order, $data, $request->user());

        return back()->with('success', '成本利润已更新');
    }

    public function export(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $orders = $this->filteredOrders($request);

        return response()->streamDownload(function () use ($orders): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ERP订单号', '来源订单号', '来源', '收件人', '净收入', '总成本', '贡献利润', 'Margin', '利润状态', '完整性']);
            foreach ($orders as $order) {
                $summary = $this->analysis->summary($order);
                fputcsv($handle, [$order->erp_order_id, $order->external_order_number, $order->source_type->value, $order->address?->recipient_name, $summary['net_revenue'], $summary['total_cost'], $summary['contribution_profit'], $summary['margin'], $summary['profit_status'], $summary['completeness']]);
            }
            fclose($handle);
        }, 'order-analysis.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filteredOrders(Request $request): \Illuminate\Support\Collection
    {
        $orders = Order::query()->with(['address', 'financials', 'costEntries', 'owner'])
            ->when($request->input('source_type'), fn ($query, string $source) => $query->where('source_type', $source))
            ->when($request->input('date_from'), fn ($query, string $date) => $query->whereDate('order_date', '>=', $date))
            ->when($request->input('date_to'), fn ($query, string $date) => $query->whereDate('order_date', '<=', $date))
            ->when($request->input('country'), fn ($query, string $country) => $query->whereHas('address', fn ($address) => $address->where('country', $country)))
            ->when($request->input('sku'), fn ($query, string $sku) => $query->whereHas('items', fn ($items) => $items->where('sku', 'like', "%{$sku}%")))
            ->when($request->input('search'), function ($query, string $search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('internal_order_number', 'like', "%{$search}%")
                        ->orWhere('erp_order_id', 'like', "%{$search}%")
                        ->orWhereHas('address', fn ($address) => $address->where('recipient_name', 'like', "%{$search}%"))
                        ->orWhereHas('items', fn ($items) => $items->where('product_summary', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"));
                });
            })->latest('order_date')->latest('id')->get();

        return $orders->filter(function (Order $order) use ($request): bool {
            $summary = $this->analysis->summary($order);
            if ($request->input('completeness') && $summary['completeness'] !== $request->input('completeness')) {
                return false;
            }
            if ($request->input('profit_status') && $summary['profit_status'] !== $request->input('profit_status')) {
                return false;
            }

            return true;
        })->values();
    }
}
