<?php

declare(strict_types=1);

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
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
});
fwrite(STDOUT, "GLOBAL_GRID_SYNTHETIC_FIXTURES_PASS: 1 product, 1 inventory, 1 procurement; disposable SQLite only\n");
