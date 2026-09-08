<?php

namespace App\Modules\Dashboard\Application;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

final class DashboardMetrics
{
    public function current(): array
    {
        if (! Schema::hasTable('orders')) {
            return [
                'pending_orders' => 0,
                'pending_shipment' => 0,
                'shipped_pending_sync' => 0,
                'completed_today' => 0,
            ];
        }

        return [
            'pending_orders' => DB::table('orders')->where('order_status', 'pending_shipment')->count(),
            'pending_shipment' => DB::table('orders')->where('order_status', 'pending_shipment')->count(),
            'shipped_pending_sync' => DB::table('shipments')
                ->join('manual_syncs', 'manual_syncs.order_id', '=', 'shipments.order_id')
                ->where('shipments.shipment_status', 'shipped')
                ->where('manual_syncs.website_sync_status', 'pending')
                ->count(),
            'completed_today' => DB::table('manual_syncs')
                ->where('website_sync_status', 'completed')
                ->whereDate('synced_at', today())
                ->count(),
        ];
    }

    public function procurementProgress(): array
    {
        $empty = [
            'active' => 0,
            'counts' => ['pending' => 0, 'purchasing' => 0, 'ordered' => 0, 'completed' => 0, 'cancelled' => 0],
            'recent' => [],
        ];
        if (! Schema::hasTable('procurement_orders')) return $empty;

        $counts = $empty['counts'];
        DB::table('procurement_orders')
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->get()
            ->each(function ($row) use (&$counts): void {
                if (array_key_exists($row->status, $counts)) $counts[$row->status] = (int) $row->aggregate;
            });

        $recent = DB::table('procurement_orders')
            ->leftJoin('orders', 'orders.id', '=', 'procurement_orders.source_order_id')
            ->select([
                'procurement_orders.id', 'procurement_orders.procurement_number', 'procurement_orders.status',
                'procurement_orders.carrier', 'procurement_orders.tracking_number', 'procurement_orders.updated_at',
                'orders.erp_order_id as source_order_erp_id',
            ])
            ->orderByDesc('procurement_orders.updated_at')
            ->orderByDesc('procurement_orders.id')
            ->limit(6)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        return [
            'active' => $counts['pending'] + $counts['purchasing'] + $counts['ordered'],
            'counts' => $counts,
            'recent' => $recent,
        ];
    }
}
