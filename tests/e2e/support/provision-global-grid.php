<?php

declare(strict_types=1);

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Models\LogisticsReview;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderSourceType;
use App\Modules\Orders\Application\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Procurement\Infrastructure\Models\ProcurementOrder;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

function deny(string $reason): never
{
    fwrite(STDERR, 'Global Grid synthetic fixture denied: '.$reason.PHP_EOL);
    exit(1);
}

$repo = realpath(dirname(__DIR__, 3));
$root = realpath((string) getenv('BYL_E2E_RUN_ROOT'));
$db = realpath((string) getenv('DB_DATABASE'));
if (! $repo || ! $root || ! $db || str_starts_with($root, $repo.DIRECTORY_SEPARATOR)) deny('workspace path');
if (! str_starts_with($root, realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'byl-erp-e2e-')) deny('run root not disposable');
if (dirname($db) !== $root.DIRECTORY_SEPARATOR.'database' || basename($db) !== 'e2e.sqlite') deny('database path');
if (getenv('BYL_E2E_DISPOSABLE') !== 'true' || getenv('APP_ENV') !== 'testing' ||
    getenv('DB_CONNECTION') !== 'sqlite' || getenv('ERP_LOCAL_ADMIN_BYPASS') !== 'false') deny('environment');
require $repo.'/vendor/autoload.php';
$app = require $repo.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'sqlite') deny('effective runtime');
if (realpath((string) config('database.connections.sqlite.database')) !== $db) deny('effective database');
if ($app->configurationIsCached() || $app->routesAreCached()) deny('cached runtime');
$runId = preg_replace('/[^a-z0-9]/i', '', (string) getenv('BYL_E2E_RUN_ID'));
if (! $runId || strlen($runId) < 20) deny('run id');
$owner = User::query()->where('email', "authorized-{$runId}@byl-e2e.invalid")->firstOrFail();
$product = Product::query()->where('sku', 'E2E-SKU-0001')->firstOrFail();
$warehouse = Warehouse::query()->where('slug', 'us-la')->firstOrFail();
DB::transaction(function () use ($product, $warehouse, $owner): void {
    if (ProcurementOrder::query()->exists() || WarehouseInventory::query()->exists()) deny('database not fresh');
    WarehouseInventory::query()->create([
        'warehouse_id'=>$warehouse->id, 'product_id'=>$product->id,
        'quantity_on_hand'=>18, 'quantity_reserved'=>3,
        'inbound_at'=>now()->subDays(30), 'location'=>'E2E-BIN-007',
    ]);
    $order = Order::query()->create([
        'internal_order_number'=>'INT-E2E-GG-007',
        'erp_order_id'=>'007-1110-c99990',
        'source_type'=>OrderSourceType::Manual,
        'order_kind'=>OrderKind::Customer,
        'external_order_number'=>'E2E-GG-SRC-007',
        'order_date'=>now()->toDateString(),
        'owner_id'=>$owner->id,
        'order_status'=>OrderStatus::PendingShipment,
    ]);
    $item = $order->items()->create([
        'product_id'=>$product->id, 'sku'=>'E2E-SKU-0001',
        'product_summary'=>'E2E Synthetic Product', 'quantity'=>2,
        'unit_price'=>'19.50','line_revenue'=>'39.00',
    ]);
    $procurement = ProcurementOrder::query()->create([
        'procurement_number'=>'PO-E2E-GG-0001',
        'source_order_id'=>$order->id,'status'=>'pending','created_by'=>$owner->id,
    ]);
    $procurement->items()->create([
        'source_order_item_id'=>$item->id,'product_id'=>$product->id,
        'sku'=>'E2E-SKU-0001','product_summary'=>'E2E Synthetic Product',
        'source_quantity'=>2,'purchase_quantity'=>2,
    ]);
    // R4-L1 Logistics: isolated synthetic domestic and review fixtures.
    $factory = Warehouse::query()->where('code', 'FACTORY')->firstOrFail();
    $domestic = Order::query()->create([
        'internal_order_number'=>'INT-E2E-GG-DOM-01',
        'erp_order_id'=>'007-1110-c99992',
        'source_type'=>OrderSourceType::Manual,
        'order_kind'=>OrderKind::Customer,
        'external_order_number'=>'E2E-GG-DOM-01',
        'order_date'=>now()->toDateString(),
        'owner_id'=>$owner->id,
        'order_status'=>OrderStatus::PendingShipment,
    ]);
    $domestic->address()->create([
        'recipient_name'=>'E2E Domestic Recipient',
        'phone'=>'15550001234','country'=>'DE','city'=>'Berlin',
        'address_line_1'=>'Synthetic Address 12','postal_code'=>'10001',
    ]);
    $domestic->items()->create([
        'product_id'=>$product->id,'sku'=>'E2E-SKU-0001',
        'product_summary'=>'E2E Domestic Product', 'quantity'=>1,
        'unit_price'=>'12.00','line_revenue'=>'12.00',
    ]);
    $domestic->shipment()->create([
        'warehouse_id'=>$factory->id,
        'shipment_status'=>'pending',
        'warehouse_selection_source'=>'manual',
    ]);
    // Independent R4 stockout view fixture, never a real warehouse/order.
    // Distinct pending factory shipment with authoritative stockout status.
    $stockout = Order::query()->create([
        'internal_order_number'=>'INT-E2E-GG-STOCKOUT-01',
        'erp_order_id'=>'007-1110-c99991',
        'source_type'=>OrderSourceType::Manual,
        'order_kind'=>OrderKind::Customer,
        'external_order_number'=>'E2E-GG-STOCKOUT-01',
        'order_date'=>now()->toDateString(),
        'owner_id'=>$owner->id,
        'order_status'=>OrderStatus::PendingShipment,
    ]);
    $stockout->forceFill(['workflow_status'=>'out_of_stock'])->save();
    $stockout->address()->create([
        'recipient_name'=>'E2E Stockout Recipient',
        'phone'=>'15550005678','country'=>'DE','city'=>'Berlin',
        'address_line_1'=>'Synthetic Stockout Street 7','postal_code'=>'10002',
    ]);
    $stockout->items()->create([
        'product_id'=>$product->id,'sku'=>'E2E-SKU-0001',
        'product_summary'=>'E2E Out of Stock Product', 'quantity'=>1,
        'unit_price'=>'11.00','line_revenue'=>'11.00',
    ]);
    $stockout->shipment()->create([
        'warehouse_id'=>$factory->id,
        'shipment_status'=>'pending',
        'warehouse_selection_source'=>'manual',
    ]);
    // R4-3 isolated approved factory order; tests NEVER touch Production.
    $pending = Order::query()->create([
        'internal_order_number'=>'INT-E2E-GG-PENDING-01',
        'erp_order_id'=>'007-1111-c99990',
        'source_type'=>OrderSourceType::Manual,
        'order_kind'=>OrderKind::Customer,
        'external_order_number'=>'E2E-GG-PENDING-01',
        'order_date'=>now()->toDateString(),
        'owner_id'=>$owner->id,
        'order_status'=>OrderStatus::PendingShipment,
    ]);
    $pending->address()->create([
        'recipient_name'=>'E2E Pending Recipient',
        'phone'=>'15550009090','country'=>'DE','city'=>'Berlin',
        'address_line_1'=>'Synthetic Pending Street 90','postal_code'=>'10009',
    ]);
    $pending->items()->create([
        'product_id'=>$product->id,'sku'=>'E2E-SKU-0001',
        'product_summary'=>'E2E Pending Product', 'quantity'=>1,
        'unit_price'=>'18.00','line_revenue'=>'18.00',
    ]);
    $pending->shipment()->create([
        'warehouse_id'=>$factory->id, 'shipment_status'=>'pending',
        'warehouse_selection_source'=>'manual',
    ]);
    $pending->operationalMarkers()->create(['marker'=>'domestic_reviewed','marked_at'=>now()]);
    // R4-3-F isolated country-default/inline-override fixture.
    $auto = Order::query()->create([
        'internal_order_number'=>'INT-E2E-GG-AUTO-01',
        'erp_order_id'=>'007-1111-c99989',
        'source_type'=>OrderSourceType::Manual,
        'order_kind'=>OrderKind::Customer,
        'external_order_number'=>'E2E-GG-AUTO-01',
        'order_date'=>now()->toDateString(),
        'owner_id'=>$owner->id,
        'order_status'=>OrderStatus::PendingShipment,
    ]);
    $auto->address()->create([
        'recipient_name'=>'E2E Automatic Route Recipient',
        'phone'=>'15550009889','country'=>'MX','city'=>'Mexico City',
        'address_line_1'=>'Synthetic Mexico Street 89','postal_code'=>'01000',
    ]);
    $auto->items()->create([
        'product_id'=>$product->id,'sku'=>'E2E-SKU-0001',
        'product_summary'=>'E2E Auto Route Product','quantity'=>1,
        'unit_price'=>'18.00','line_revenue'=>'18.00',
    ]);
    $auto->shipment()->create([
        'warehouse_id'=>$factory->id,'shipment_status'=>'pending',
        'warehouse_selection_source'=>'manual',
    ]);
    $auto->operationalMarkers()->create(['marker'=>'domestic_reviewed','marked_at'=>now()]);
    app(\App\Modules\Logistics\Application\Domestic\DomesticCarrierDefaults::class)
        ->fill($auto->fresh(['address','shipment']));
    LogisticsReview::query()->create([
        'order_id'=>$domestic->id,
        'status'=>'pending',
        'requested_at'=>now(),
        'reason'=>'E2E Synthetic Review',
        'trigger'=>'redispatch',
    ]);
});
fwrite(STDOUT, "GLOBAL_GRID_SYNTHETIC_FIXTURES_PASS: 1 product, 1 inventory, 1 procurement, 1 domestic review; disposable SQLite only\n");
