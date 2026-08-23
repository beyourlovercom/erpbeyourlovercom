<?php

namespace App\Modules\Dashboard\Application;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

final class DashboardDataContract
{
    public function current(Authenticatable $user): array
    {
        $dayStart = now('UTC')->startOfDay();
        $dayEnd = $dayStart->copy()->addDay();

        return [
            'time' => [
                'timezone' => 'UTC',
                'day_window' => 'inclusive_start_exclusive_end',
            ],
            'metrics' => [
                ...app(DashboardMetrics::class)->current(),
                'shipped_orders_today' => DB::table('shipments')
                    ->where('shipment_status', 'shipped')
                    ->where('shipped_at', '>=', $dayStart)
                    ->where('shipped_at', '<', $dayEnd)
                    ->count(),
            ],
            'order_source_counts' => [
                'website' => DB::table('orders')->where('source_type', 'website')->count(),
                'kol' => DB::table('orders')->where('source_type', 'kol')->count(),
                'manual_other' => DB::table('orders')->where('source_type', 'manual_other')->count(),
            ],
            'coverage' => [
                'pending_shipment_history' => 'not_available',
                'product_performance' => 'not_available',
                'inventory_warehouse_status' => 'not_available',
                'estimated_shipping_cost' => 'not_available',
                'shipped_line_item_history' => 'not_available',
                'permission_data_scope' => 'not_defined',
            ],
        ];
    }
}
