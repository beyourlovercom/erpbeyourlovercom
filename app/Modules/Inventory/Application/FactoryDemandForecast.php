<?php

namespace App\Modules\Inventory\Application;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Factory-only demand projection. Based on paid WooCommerce customer orders
 * across every fulfillment warehouse; does not mutate inventory or orders.
 */
final class FactoryDemandForecast
{
    /** @param Collection<int, object> $inventories
     *  @return array<int, array<string, int|float|string|bool|null>>
     */
    public function forInventories(Collection $inventories): array
    {
        $productIds = $inventories->pluck('product_id')->filter()->unique()->values()->all();
        if ($productIds === []) return [];

        $sales = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->join('order_financials as f', 'f.order_id', '=', 'o.id')
            ->whereIn('oi.product_id', $productIds)
            ->where('o.order_date', '>=', today()->subDays(89)->toDateString())
            ->where('o.order_date', '<', today()->addDay()->toDateString())
            ->where('o.order_kind', 'customer')
            ->where('o.source_type', 'woocommerce')
            ->whereNull('o.fulfillment_parent_id')
            ->whereNull('o.deleted_at')
            ->where('o.order_status', '!=', 'cancelled')
            ->whereNotNull('f.paid_at')
            ->whereIn('f.provider_order_status', ['processing', 'completed'])
            ->groupBy('oi.product_id')
            ->selectRaw('oi.product_id, SUM(oi.quantity) as units_90d')
            ->pluck('units_90d', 'oi.product_id');

        $results = [];
        foreach ($inventories as $row) {
            $results[(int) $row->id] = self::calculate(
                (int) ($sales[(int) $row->product_id] ?? 0),
                (int) $row->quantity_available,
                $row->two_month_demand_override === null ? null : (int) $row->two_month_demand_override,
            );
        }
        return $results;
    }

    /** @return array<string, int|float|string|bool|null> */
    public static function calculate(int $sales90d, int $available, ?int $override): array
    {
        $sales90d = max(0, $sales90d);
        $available = max(0, $available);
        $auto = (int) ceil($sales90d * 2 / 3);
        $threshold = $override ?? $auto;
        $shortage = max(0, $threshold - $available);
        $warning = $threshold > 0 && $available < $threshold;
        $status = $warning ? 'warning' : ($sales90d === 0 && $override === null ? 'no_sales' : 'normal');

        return [
            'sales_90d' => $sales90d,
            'monthly_average' => round($sales90d / 3, 2),
            'automatic_two_month_demand' => $auto,
            'two_month_demand' => $threshold,
            'manual_two_month_demand' => $override,
            'is_manual' => $override !== null,
            'shortage_quantity' => $shortage,
            'coverage_ratio' => $threshold > 0 ? round($available / $threshold, 4) : null,
            'status' => $status,
            'status_label' => match ($status) {
                'warning' => '库存告急',
                'no_sales' => '暂无销量',
                default => '正常',
            },
        ];
    }
}
