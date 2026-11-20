<?php

namespace Database\Seeders;

use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderSourceType;
use App\Modules\Orders\Application\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Synthetic, local-only rows for visually checking the six force-return grids. */
final class WarehouseBatchReturnAllocationUatSeeder extends Seeder
{
    public function run(): void
    {
        abort_unless(app()->environment(['local', 'testing']) && config('database.default') === 'sqlite',
            500, 'The six-grid UAT seed is restricted to local/testing SQLite.');

        DB::transaction(function (): void {
            $admin = User::query()->where('email', 'admin@local.test')->firstOrFail();
            $permission = Permission::query()->firstOrCreate(
                ['key' => 'domestic.review'], ['label' => 'Domestic business review'],
            );
            foreach ($admin->roles as $role) {
                $role->permissions()->syncWithoutDetaching([$permission->id]);
            }

            $fixtures = [
                ['D-REVIEW', 'FACTORY', 'pending_review', 'pending'],
                ['D-STOCKOUT', 'FACTORY', 'out_of_stock', 'stockout'],
                ['D-PENDING', 'FACTORY', 'sending', 'approved'],
                ['O-REVIEW', 'US-NY', 'pending_review', 'pending'],
                ['O-STOCKOUT', 'US-NY', 'out_of_stock', 'stockout'],
                ['O-PENDING', 'US-NY', 'sending', 'approved'],
            ];

            foreach ($fixtures as [$suffix, $code, $workflow, $review]) {
                $warehouse = Warehouse::query()->where('code', $code)->firstOrFail();
                $reference = 'UAT-BATCH-'.$suffix;
                $order = Order::query()->firstOrCreate(['internal_order_number' => $reference], [
                    'erp_order_id' => $reference,
                    'external_order_number' => $reference,
                    'source_type' => OrderSourceType::Manual,
                    'order_kind' => OrderKind::Customer,
                    'order_date' => now()->toDateString(),
                    'order_status' => OrderStatus::PendingShipment,
                    'workflow_status' => $workflow,
                    'routing_warehouse_code' => $code,
                    'owner_id' => $admin->id,
                    'currency' => 'USD',
                    'order_note' => 'SYNTHETIC LOCAL UAT ONLY',
                ]);
                $order->address()->firstOrCreate([], [
                    'recipient_name' => 'Synthetic '.$suffix,
                    'phone' => '+1 555 0100', 'country' => 'US',
                    'province_state' => 'NY', 'city' => 'New York',
                    'address_line_1' => '100 Synthetic Street', 'postal_code' => '10001',
                ]);
                $order->items()->firstOrCreate(['sku' => 'UAT-SYNTHETIC-SKU'], [
                    'product_summary' => 'Six-grid UAT synthetic sample',
                    'quantity' => 1, 'unit_price' => '10.00', 'line_revenue' => '10.00',
                ]);
                $order->shipment()->firstOrCreate([], [
                    'warehouse_id' => $warehouse->id,
                    'warehouse_selection_source' => 'manual',
                    'shipment_status' => 'pending',
                    'carrier' => $code === 'FACTORY' ? '4PX' : 'USPS',
                    'service' => 'Synthetic Service',
                ]);

                if ($code === 'FACTORY' && $review !== 'pending') {
                    $order->operationalMarkers()->firstOrCreate([
                        'marker' => $review === 'stockout' ? 'domestic_stockout' : 'domestic_reviewed',
                    ], ['marked_by' => $admin->id, 'marked_at' => now()]);
                }
                if ($code !== 'FACTORY' && $review !== 'pending') {
                    $order->overseaWarehouseReview()->firstOrCreate([], [
                        'status' => $review,
                        'submitted_warehouse_id' => $warehouse->id,
                        'reviewed_warehouse_id' => $warehouse->id,
                        'reviewed_by' => $admin->id,
                        'reviewed_at' => now(),
                    ]);
                }
            }
        });
    }
}
