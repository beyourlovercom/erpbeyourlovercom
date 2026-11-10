<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Models\OverseaWarehouseReview;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Database\Seeder;
use RuntimeException;

final class MorePlusBatchDevUatSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local') || ! config('erp_local.admin_bypass.enabled')) {
            throw new RuntimeException('MorePlus test data is strictly local UAT only');
        }
        $role = Role::query()->firstOrCreate(['key' => 'super_admin'], ['label' => 'UAT Administrator']);
        $user = User::query()->firstOrCreate(['email' => 'moreplus-uat@example.test'], [
            'name' => 'MorePlus Dev Reviewer', 'password' => str_repeat('x', 48), 'is_active' => true,
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);
        $wh = Warehouse::query()->firstOrCreate(['code' => 'US-LA'], [
            'slug' => 'us-la', 'name' => '美西 MorePlus 仓',
            'type' => 'warehouse', 'is_active' => true, 'priority' => 10,
        ]);
        $sku = Product::query()->firstOrCreate(['sku' => 'UAT-MOREPLUS-001'], [
            'name' => 'Synthetic MorePlus Test SKU', 'product_type' => 'simple', 'is_active' => true,
        ]);
        foreach ([42141, 42142, 42143] as $idx => $number) {
            $order = Order::query()->firstOrCreate(['erp_order_id' => '007-1109-c'.$number], [
                'internal_order_number' => 'UAT-MOREPLUS-'.$number,
                'source_type' => 'manual_other', 'order_kind' => 'customer',
                'external_order_number' => 'UAT-ORIGINAL-'.$number,
                'order_date' => '2026-11-09', 'order_status' => 'pending_shipment',
            ]);
            if (! $order->items()->exists()) {
                $order->items()->create([
                    'product_id' => $sku->id, 'sku' => $sku->sku, 'product_summary' => $sku->name,
                    'quantity' => 1, 'unit_price' => '0.00', 'line_revenue' => '0.00',
                ]);
            }
            if (! $order->address()->exists()) {
                $order->address()->create([
                    'recipient_name' => 'UAT Synthetic Order '.$number,
                    'country' => 'US', 'province_state' => 'CA', 'city' => 'Test',
                    'address_line_1' => '100 Test Street', 'postal_code' => '90210',
                ]);
            }
            $order->shipment()->firstOrCreate(['order_id' => $order->id], [
                'warehouse_id' => $wh->id, 'shipment_status' => 'pending',
                'warehouse_selection_source' => 'manual', 'carrier' => 'USPS',
            ]);
            OverseaWarehouseReview::query()->firstOrCreate(['order_id' => $order->id], [
                'submitted_warehouse_id' => $wh->id,
                'reviewed_warehouse_id' => $wh->id,
                'status' => $idx < 2 ? 'approved' : 'pending',
                'reviewed_by' => $user->id, 'reviewed_at' => now(),
            ]);
        }
    }
}
