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
        $orders = Order::query()->with(['address', 'financials', 'costEntries', 'owner'])
            ->when($request->input('source_type'), fn ($query, string $source) => $query->where('source_type', $source))
            ->when($request->input('completeness') === 'incomplete', fn ($query) => $query->where(function ($nested): void {
                $nested->whereDoesntHave('financials')->orWhereHas('financials', fn ($financials) => $financials->whereNull('amount_paid')->orWhereNull('refund_amount'));
            }))->latest('order_date')->latest('id')->get();

        return Inertia::render('Finance/OrderAnalysis', [
            'orders' => $orders->map(fn (Order $order): array => [
                'id' => $order->id, 'internal_order_number' => $order->internal_order_number,
                'source_type' => $order->source_type->value, 'recipient_name' => $order->address?->recipient_name,
                'summary' => $this->analysis->summary($order),
            ])->values()->all(),
            'fields' => $this->analysis->fields(),
        ]);
    }

    public function update(Request $request, Order $order): \Illuminate\Http\RedirectResponse
    {
        $fields = array_fill_keys($this->analysis->fields(), ['nullable', 'numeric', 'min:0']);
        $data = $request->validate([...$fields, 'currency' => ['sometimes', 'string', 'size:3']]);
        $this->analysis->save($order, $data, $request->user());

        return back()->with('success', '成本利润已更新');
    }

    public function export(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $orders = Order::query()->with(['address', 'financials', 'costEntries'])
            ->when($request->input('source_type'), fn ($query, string $source) => $query->where('source_type', $source))
            ->when($request->input('completeness') === 'incomplete', fn ($query) => $query->where(function ($nested): void {
                $nested->whereDoesntHave('financials')->orWhereHas('financials', fn ($financials) => $financials->whereNull('amount_paid')->orWhereNull('refund_amount'));
            }))
            ->latest('order_date')->get();

        return response()->streamDownload(function () use ($orders): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['订单号', '来源', '收件人', '净收入', '总成本', '贡献利润', '利润状态', '完整性']);
            foreach ($orders as $order) {
                $summary = $this->analysis->summary($order);
                fputcsv($handle, [$order->internal_order_number, $order->source_type->value, $order->address?->recipient_name, $summary['net_revenue'], $summary['total_cost'], $summary['contribution_profit'], $summary['profit_status'], $summary['completeness']]);
            }
            fclose($handle);
        }, 'order-analysis.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
