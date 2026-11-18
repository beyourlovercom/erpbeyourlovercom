<?php

namespace Database\Seeders;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductWarehouseSku;
use Illuminate\Database\Seeder;

/** Synthetic-only fixture: it must never run against real order or warehouse data. */
final class WarehouseFinalSkuDevUatSeeder extends Seeder
{
    public function run(): void
    {
        abort_unless(app()->environment(['local', 'testing'])
            && config('database.default') === 'sqlite'
            && str_contains((string) config('database.connections.sqlite.database'), 'warehouse-final-sku-dev-20261118.sqlite')
            && (string) env('BYL_FINAL_SKU_DEV_UAT_ONLY') === 'approved',
            500, 'Final SKU fixtures require the isolated, explicitly approved SQLite Dev database.');

        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $moreplus = Warehouse::query()->where('code', 'US-LA')->firstOrFail();
        $germany = Warehouse::query()->where('code', 'DE')->firstOrFail();
        $factory = Warehouse::query()->where('code', 'FACTORY')->firstOrFail();

        $lubricant = Product::query()->updateOrCreate(['sku' => 'UAT-BY489A-Lubes'], [
            'name' => '[DEV UAT] Warehouse-mapped lubricant',
            'product_type' => 'simple', 'source_type' => 'manual', 'is_active' => true,
        ]);
        $accessory = Product::query()->updateOrCreate(['sku' => 'UAT-SF036C'], [
            'name' => '[DEV UAT] Factory accessory',
            'product_type' => 'simple', 'source_type' => 'manual', 'is_active' => true,
        ]);

        foreach ([
            [$lubricant, $moreplus, 'UAT-MP-BY489A'],
            [$lubricant, $germany, 'UAT-DE-BY489A'],
            [$lubricant, $factory, 'UAT-CN-BY489A'],
            [$accessory, $factory, 'UAT-CN-SF036C'],
        ] as [$product, $warehouse, $physicalSku]) {
            ProductWarehouseSku::query()->updateOrCreate(
                ['product_id' => $product->id, 'warehouse_id' => $warehouse->id],
                [
                    'sku' => $physicalSku, 'is_active' => true,
                    'verified_at' => now(), 'verified_by' => $owner->id,
                    'verification_note' => 'SYNTHETIC DEV UAT fixture only; NOT verified against a real provider',
                ]
            );
        }

        foreach ([
            ['007-1118-c42390', $lubricant, $moreplus, 'UAT-MP-BY489A', 'USPS'],
            ['007-1118-c42391', $lubricant, $germany, 'UAT-DE-BY489A', 'DHL'],
            ['007-1118-c42392', $accessory, $factory, 'UAT-CN-SF036C', 'Default'],
        ] as [$number, $product, $warehouse, $finalSku, $carrier]) {
            $order = Order::query()->updateOrCreate(['erp_order_id' => $number], [
                'internal_order_number' => $number,
                'external_order_number' => $number,
                'source_type' => 'manual_other',
                'order_kind' => 'customer', 'order_status' => 'pending_shipment',
                'order_date' => now()->toDateString(), 'owner_id' => $owner->id,
                'routing_warehouse_code' => $warehouse->code,
                'order_note' => 'SYNTHETIC DEV UAT; NEVER SEND TO A WAREHOUSE',
            ]);
            $order->address()->updateOrCreate(['order_id' => $order->id], [
                'recipient_name' => 'Synthetic Final SKU UAT', 'phone' => '5550001000',
                'country' => 'US', 'province_state' => 'CA', 'city' => 'Test City',
                'postal_code' => '90210', 'address_line_1' => '100 Test Example St',
            ]);
            $order->items()->updateOrCreate(['product_id' => $product->id], [
                'product_summary' => $product->name, 'source_product_summary' => $product->name,
                'source_product_id' => $product->id,
                'sku' => $product->sku, 'source_sku' => $product->sku,
                'final_sku' => $finalSku, 'resolved_fulfillment_sku' => $finalSku,
                'quantity' => 1, 'unit_price' => '10.00', 'line_revenue' => '10.00',
            ]);
            $order->shipment()->updateOrCreate(['order_id' => $order->id], [
                'warehouse_id' => $warehouse->id,
                'warehouse_selection_source' => 'manual',
                'shipment_status' => 'pending', 'carrier' => $carrier,
                'logistics_note' => 'DEV UAT ONLY; no external dispatch',
            ]);
        }
    }
}
