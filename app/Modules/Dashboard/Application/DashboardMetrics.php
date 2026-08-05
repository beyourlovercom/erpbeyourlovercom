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
}
