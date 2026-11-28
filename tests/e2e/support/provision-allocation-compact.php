<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Logistics\Application\FulfillmentAllocationSubmissionService;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Contracts\Console\Kernel;

$repo = realpath(dirname(__DIR__, 3));
$root = realpath((string) getenv('BYL_E2E_RUN_ROOT'));
$db = realpath((string) getenv('DB_DATABASE'));
if (! $root || ! $db || ! str_starts_with($root, realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'byl-erp-e2e-')
    || $db !== $root.'/database/e2e.sqlite' || getenv('APP_ENV') !== 'testing'
    || getenv('BYL_E2E_DISPOSABLE') !== 'true' || getenv('DB_CONNECTION') !== 'sqlite'
    || getenv('ERP_LOCAL_ADMIN_BYPASS') !== 'false') {
    throw new RuntimeException('Disposable database required');
}
require $repo.'/vendor/autoload.php';
$app = require $repo.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'sqlite'
    || realpath((string) config('database.connections.sqlite.database')) !== $db
    || $app->configurationIsCached() || $app->routesAreCached()) {
    throw new RuntimeException('Effective runtime not isolated');
}
$runId = preg_replace('/[^a-z0-9]/i', '', (string) getenv('BYL_E2E_RUN_ID'));
$actor = User::query()->where('email', 'logistics-operator-'.$runId.'@byl-e2e.invalid')->firstOrFail();
$role = $actor->roles()->firstOrFail();
foreach (['orders.view','orders.edit','products.view'] as $key) {
    $role->permissions()->syncWithoutDetaching([\App\Models\Permission::query()->firstOrCreate(['key'=>$key], ['label'=>$key])->id]);
}
if (in_array('--verify', $argv, true)) {
    $first = Order::query()->where('erp_order_id','E2E-COMPACT-02')->firstOrFail();
    $second = Order::query()->where('erp_order_id','E2E-COMPACT-01')->firstOrFail();
    if ($first->items()->orderBy('sku')->pluck('sku')->all() !== ['E2E-COMPACT-A','E2E-COMPACT-C']
        || $first->fulfillmentAllocation?->status !== 'submitted'
        || $second->fulfillmentAllocation?->status === 'submitted') {
        throw new RuntimeException('Allocation DB readback mismatch');
    }
    echo "COMPACT_DB_READBACK_PASS\n"; exit;
}
$products = [];
foreach (['A','B','C'] as $suffix) {
    $products[$suffix] = Product::query()->create(['sku'=>'E2E-COMPACT-'.$suffix,'name'=>'Synthetic Compact '.$suffix,
        'source_type'=>'manual','product_type'=>'simple','is_active'=>true]);
}
foreach (['US-NY'=>20,'CA'=>2,'DE'=>5,'US-SM'=>30,'US-LA'=>10] as $code=>$quantity) {
    $warehouse=Warehouse::query()->where('code',$code)->firstOrFail();
    WarehouseInventory::query()->create(['warehouse_id'=>$warehouse->id,'product_id'=>$products['A']->id,
        'quantity_on_hand'=>$quantity,'quantity_reserved'=>0,'inbound_at'=>now()->subDays(3)]);
}
foreach (['01','02'] as $number) {
    $order=Order::query()->create(['internal_order_number'=>'E2E-COMPACT-'.$number,'erp_order_id'=>'E2E-COMPACT-'.$number,
        'external_order_number'=>'E2E-COMPACT-'.$number,'source_type'=>'manual','order_kind'=>'customer',
        'order_date'=>now()->toDateString(),'order_status'=>'pending_shipment','currency'=>'USD','owner_id'=>$actor->id]);
    $order->address()->create(['recipient_name'=>'Synthetic Compact','country'=>'US','province_state'=>'NY',
        'city'=>'New York','address_line_1'=>'123 Synthetic Road','postal_code'=>'10001','phone'=>'10000000000']);
    foreach (['A','B'] as $suffix) {
        $product=$products[$suffix];
        $order->items()->create(['product_id'=>$product->id,'sku'=>$product->sku,'product_summary'=>$product->name,
            'quantity'=>1,'unit_price'=>'0.00','line_revenue'=>'0.00']);
    }
}
echo "COMPACT_FIXTURE_PASS\n";
