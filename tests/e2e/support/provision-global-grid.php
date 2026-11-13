<?php

declare(strict_types=1);

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Models\LogisticsReview;
use App\Modules\Logistics\Infrastructure\Models\OverseaWarehouseReview;
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
    // Overseas batch-shipment *visual* fixture. The Chrome suite never
    // submits real shipment writes; the only POST is an intercepted preview.
    $ny = Warehouse::query()->firstOrCreate(['code'=>'US-NY'], [
        'slug'=>'us-ny', 'name'=>'美东纽约仓', 'type'=>'warehouse',
        'is_active'=>true, 'priority'=>10,
    ]);
    $overseaBulk = Order::query()->create([
        'internal_order_number'=>'INT-E2E-GG-OVERSEA-BULK-01',
        'erp_order_id'=>'007-E2E-GG-BULK-01',
        'source_type'=>OrderSourceType::Manual,
        'order_kind'=>OrderKind::Customer,
        'external_order_number'=>'E2E-GG-OVERSEA-BULK-SRC',
        'order_date'=>now()->toDateString(),
        'owner_id'=>$owner->id,
        'order_status'=>OrderStatus::PendingShipment,
    ]);
    $overseaBulk->address()->create([
        'recipient_name'=>'E2E Overseas Preflight','phone'=>'15559998888',
        'country'=>'US','city'=>'New York','address_line_1'=>'1 Synthetic NY',
        'postal_code'=>'10001',
    ]);
    $overseaBulk->items()->create([
        'product_id'=>$product->id,'sku'=>'E2E-SKU-0001',
        'product_summary'=>'E2E Overseas Bulk Preview','quantity'=>1,
        'unit_price'=>'15.00','line_revenue'=>'15.00',
    ]);
    $overseaBulk->shipment()->create([
        'warehouse_id'=>$ny->id,'shipment_status'=>'pending',
        'warehouse_selection_source'=>'manual',
        'carrier'=>'USPS','service'=>'Priority',
        'tracking_number'=>'9400111899223856928777',
    ]);
    OverseaWarehouseReview::query()->create([
        'order_id'=>$overseaBulk->id,
        'submitted_warehouse_id'=>$ny->id,'reviewed_warehouse_id'=>$ny->id,
        'status'=>'approved','progress'=>'sending_pending',
        'reviewed_by'=>$owner->id,'reviewed_at'=>now(),
    ]);
    // R5-L2 three separate synthetic queue rows, all in ephemeral SQLite.
    // No real order, ShipOut or Woo API is called by the browser test.
    $r5l2Cases = [
        ['AWAIT','007-E2E-R5L2-AWAIT',null,'shipped',''],
        ['TRANSIT','007-E2E-R5L2-TRANSIT','E2E-R5L2-TRANSIT-TRK','shipped',''],
        ['EXCEPTION','007-E2E-R5L2-EXCEPTION','E2E-R5L2-EXCEPTION-TRK','exception','E2E synthetic delayed parcel'],
    ];
    foreach($r5l2Cases as [$suffix,$number,$tracking,$status,$note]){
        $l2Order=Order::query()->create([
            'internal_order_number'=>'INT-E2E-R5L2-'.$suffix,
            'erp_order_id'=>$number,
            'source_type'=>OrderSourceType::Manual,
            'order_kind'=>OrderKind::Customer,
            'external_order_number'=>'E2E-R5L2-SRC-'.$suffix,
            'order_date'=>now()->toDateString(),
            'owner_id'=>$owner->id,
            'order_status'=>OrderStatus::Shipped,
        ]);
        $l2Order->address()->create([
            'recipient_name'=>'E2E L2 '.$suffix,
            'phone'=>'15550001456','country'=>'DE','city'=>'Berlin',
            'address_line_1'=>'Synthetic L2 Street '.$suffix,'postal_code'=>'10003',
        ]);
        $l2Order->items()->create([
            'product_id'=>$product->id,'sku'=>'E2E-SKU-0001',
            'product_summary'=>'E2E L2 Synthetic Logistics Product',
            'quantity'=>1,'unit_price'=>'10.00','line_revenue'=>'10.00',
        ]);
        $l2Order->shipment()->create([
            'warehouse_id'=>$warehouse->id,
            'warehouse_selection_source'=>'manual',
            'carrier'=>'DHL','service'=>'Paket Domestic',
            'shipment_status'=>$status,
            'tracking_number'=>$tracking,'shipped_at'=>now(),
            'logistics_note'=>$note ?: null,
        ]);
    }
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
    // R4-5: additional approved 4PX provider row, appended to the pending
    // test queue to preserve the existing first-row assertions.
    $speeda = Order::query()->create([
        'internal_order_number'=>'INT-E2E-GG-SPEEDA-01',
        'erp_order_id'=>'007-1111-c99986',
        'source_type'=>OrderSourceType::Manual,
        'order_kind'=>OrderKind::Customer,
        'external_order_number'=>'E2E-GG-SPEEDA-01',
        'order_date'=>now()->addDay()->toDateString(),
        'owner_id'=>$owner->id,
        'order_status'=>OrderStatus::PendingShipment,
    ]);
    $speeda->address()->create([
        'recipient_name'=>'E2E 4PX Provider',
        'phone'=>'15550008686','country'=>'DE','city'=>'Berlin',
        'address_line_1'=>'Synthetic Provider Street 86','postal_code'=>'01000',
    ]);
    $speeda->items()->create([
        'product_id'=>$product->id,'sku'=>'E2E-SKU-0001',
        'product_summary'=>'E2E Speeda Provider','quantity'=>1,
        'unit_price'=>'15.00','line_revenue'=>'15.00',
    ]);
    $speeda->shipment()->create([
        'warehouse_id'=>$factory->id,'shipment_status'=>'pending',
        'warehouse_selection_source'=>'manual',
        'carrier'=>'4PX','service'=>'SD经济挂号-带电',
        'carrier_selection_source'=>'manual',
    ]);
    $speeda->operationalMarkers()->create(['marker'=>'domestic_reviewed','marked_at'=>now()]);
    // Independent Weship control fixture; unlike the R4-3F MX fixture,
    // no earlier E2E test changes this order's carrier.
    $weship = Order::query()->create([
        'internal_order_number'=>'INT-E2E-GG-WESHIP-01',
        'erp_order_id'=>'007-1111-c99985',
        'source_type'=>OrderSourceType::Manual,
        'order_kind'=>OrderKind::Customer,
        'external_order_number'=>'E2E-GG-WESHIP-01',
        'order_date'=>now()->addDay()->toDateString(),
        'owner_id'=>$owner->id,
        'order_status'=>OrderStatus::PendingShipment,
    ]);
    $weship->address()->create([
        'recipient_name'=>'E2E Weship Provider',
        'phone'=>'15550008585','country'=>'MX','city'=>'Mexico City',
        'address_line_1'=>'Synthetic Weship Street 85','postal_code'=>'01000',
    ]);
    $weship->items()->create([
        'product_id'=>$product->id,'sku'=>'E2E-SKU-0001',
        'product_summary'=>'E2E Weship Provider','quantity'=>1,
        'unit_price'=>'15.00','line_revenue'=>'15.00',
    ]);
    $weship->shipment()->create([
        'warehouse_id'=>$factory->id,'shipment_status'=>'pending',
        'warehouse_selection_source'=>'manual',
        'carrier'=>'Weship','service'=>'Standard',
        'carrier_selection_source'=>'manual',
    ]);
    $weship->operationalMarkers()->create(['marker'=>'domestic_reviewed','marked_at'=>now()]);
    // R4-7B Weship cumulative historical proof: a shipped order created 60
    // days ago must still appear in view=weship but never in today's provider.
    $oldWeship = Order::query()->create([
        'internal_order_number'=>'INT-E2E-R47B-OLD-WESHIP',
        'erp_order_id'=>'007-1111-c99981',
        'source_type'=>OrderSourceType::Manual,
        'order_kind'=>OrderKind::Customer,
        'external_order_number'=>'E2E-R47B-OLD-WESHIP',
        'order_date'=>now()->subDays(60)->toDateString(),
        'owner_id'=>$owner->id,
        'order_status'=>OrderStatus::PendingShipment,
    ]);
    $oldWeship->address()->create([
        'recipient_name'=>'E2E Older Weship Order',
        'phone'=>'15550008181','country'=>'DE','city'=>'Berlin',
        'address_line_1'=>'Synthetic Weship History 81','postal_code'=>'01000',
    ]);
    $oldWeship->items()->create([
        'product_id'=>$product->id,'sku'=>'E2E-SKU-0001',
        'product_summary'=>'Old Weship History','quantity'=>1,
        'unit_price'=>'10.00','line_revenue'=>'10.00',
    ]);
    $oldWeship->shipment()->create([
        'warehouse_id'=>$factory->id,
        'warehouse_selection_source'=>'manual',
        'carrier'=>'Weship','service'=>'Standard',
        'carrier_selection_source'=>'manual',
        'shipment_status'=>'shipped',
        'shipped_at'=>now()->subDays(45),
        'tracking_number'=>'E2E-R47B-WES-TRACK-OLD',
    ]);
    // R4-7A original cumulative 4PX scope: a shipped order created 60 days
    // ago must remain visible, unlike the "today-4px" date-scoped view.
    $old4px=Order::query()->create([
        'internal_order_number'=>'INT-E2E-R47-OLD-4PX',
        'erp_order_id'=>'007-1111-c99982',
        'source_type'=>OrderSourceType::Manual,
        'order_kind'=>OrderKind::Customer,
        'external_order_number'=>'E2E-R47-OLD-4PX',
        'order_date'=>now()->subDays(60)->toDateString(),
        'owner_id'=>$owner->id,
        'order_status'=>OrderStatus::PendingShipment,
    ]);
    $old4px->address()->create([
        'recipient_name'=>'E2E Older Speeda Order',
        'phone'=>'15550008282','country'=>'DE','city'=>'Berlin',
        'address_line_1'=>'Synthetic Historical Street 82','postal_code'=>'01000',
    ]);
    $old4px->items()->create([
        'product_id'=>$product->id,'sku'=>'E2E-SKU-0001',
        'product_summary'=>'Old 4PX History','quantity'=>1,
        'unit_price'=>'10.00','line_revenue'=>'10.00',
    ]);
    $old4px->shipment()->create([
        'warehouse_id'=>$factory->id,
        'warehouse_selection_source'=>'manual',
        'carrier'=>'4PX','service'=>'SD经济挂号-带电',
        'carrier_selection_source'=>'manual',
        'shipment_status'=>'shipped',
        'shipped_at'=>now()->subDays(45),
        'tracking_number'=>'E2E-R47-TRACK-OLD',
    ]);
    // R4-6 synthetic-only Factory exception fixtures. No real order,
    // external OMS, return upload, Woo or live Production state is touched.
    foreach ([
        ['id'=>'007-1111-c99984','status'=>'exception','note'=>'承运商反馈包裹延误','tracking'=>'E2E-EXCEPT-84'],
        ['id'=>'007-1111-c99983','status'=>'shipped','note'=>null,'tracking'=>'E2E-CONFLICT-83'],
    ] as $case) {
        $exception = Order::query()->create([
            'internal_order_number'=>'INT-E2E-R46-'.$case['id'],
            'erp_order_id'=>$case['id'],
            'source_type'=>OrderSourceType::Manual,
            'order_kind'=>OrderKind::Customer,
            'external_order_number'=>'E2E-R46-'.$case['id'],
            'order_date'=>now()->addDay()->toDateString(),
            'owner_id'=>$owner->id,
            'order_status'=>OrderStatus::PendingShipment,
        ]);
        $exception->address()->create([
            'recipient_name'=>'E2E Exception '.$case['id'],
            'phone'=>'15550008484','country'=>'DE','city'=>'Berlin',
            'address_line_1'=>'Synthetic Exception Street','postal_code'=>'01000',
        ]);
        $exception->items()->create([
            'product_id'=>$product->id,'sku'=>'E2E-SKU-0001',
            'product_summary'=>'E2E Exception Synthetic', 'quantity'=>1,
            'unit_price'=>'10.00','line_revenue'=>'10.00',
        ]);
        $exception->shipment()->create([
            'warehouse_id'=>$factory->id,
            'warehouse_selection_source'=>'manual',
            'carrier'=>'4PX','service'=>'SD经济挂号-带电',
            'carrier_selection_source'=>'manual',
            'shipment_status'=>$case['status'],
            'shipped_at'=>null,
            'tracking_number'=>$case['tracking'],
            'logistics_note'=>$case['note'],
        ]);
    }

    LogisticsReview::query()->create([
        'order_id'=>$domestic->id,
        'status'=>'pending',
        'requested_at'=>now(),
        'reason'=>'E2E Synthetic Review',
        'trigger'=>'redispatch',
    ]);
});
fwrite(STDOUT, "GLOBAL_GRID_SYNTHETIC_FIXTURES_PASS: 1 product, 1 inventory, 1 procurement, 1 domestic review; disposable SQLite only\n");
