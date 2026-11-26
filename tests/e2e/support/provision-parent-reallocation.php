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
$identity = 'C9900001-007';
if (in_array('--verify', $argv, true)) {
    $rootOrder = Order::query()->where('erp_order_id', $identity)->firstOrFail();
    $children = $rootOrder->fulfillmentChildren()->orderBy('id')->get();
    $old = $children->where('fulfillment_role', Order::SUPERSEDED_FULFILLMENT_ROLE);
    $active = $rootOrder->originalFulfillmentChildren()->orderBy('fulfillment_suffix')->get();
    if ($children->count() !== 4 || $old->pluck('fulfillment_suffix')->values()->all() !== ['A','B']
        || $old->contains(fn ($child) => ! $child->operational_archived_at || (int) $child->operational_archived_by !== (int) $actor->id)
        || $active->pluck('fulfillment_suffix')->all() !== ['A-2','B-2']
        || $rootOrder->fulfillmentAllocation?->status !== 'submitted'
        || Order::query()->eligibleForOperations()->whereIn('id', $old->pluck('id'))->exists()) {
        throw new RuntimeException('Database readback mismatch');
    }
    echo "R5_DB_READBACK_PASS: old A/B archived; active A-2/B-2; actor verified\n";
    exit;
}
if (Order::query()->where('erp_order_id', $identity)->exists()) throw new RuntimeException('Fixture already exists');
$order = Order::query()->create([
    'internal_order_number'=>'E2E-REALLOCATION-ROOT', 'erp_order_id'=>$identity,
    'external_order_number'=>'E2E-REALLOCATION-ROOT','source_type'=>'manual','order_kind'=>'customer',
    'order_date'=>now()->toDateString(),'order_status'=>'pending_shipment','currency'=>'USD','owner_id'=>$actor->id,
]);
$order->address()->create(['recipient_name'=>'Synthetic R5 Recipient','country'=>'US','province_state'=>'NY',
    'city'=>'New York','address_line_1'=>'123 Synthetic Road','postal_code'=>'10001','phone'=>'10000000000']);
$ny = Warehouse::query()->where('code','US-NY')->firstOrFail();
$assignments = [];
foreach (['FACTORY','US-NY'] as $i=>$warehouse) {
    $product = Product::query()->create(['sku'=>'E2E-REALLOC-'.($i+1),'name'=>'Synthetic R5 Product '.($i+1),
        'source_type'=>'manual','product_type'=>'simple','is_active'=>true]);
    $item = $order->items()->create(['product_id'=>$product->id,'sku'=>$product->sku,'product_summary'=>$product->name,
        'quantity'=>1,'unit_price'=>'10.00','line_revenue'=>'10.00']);
    WarehouseInventory::query()->create(['warehouse_id'=>$ny->id,'product_id'=>$product->id,'quantity_on_hand'=>20,
        'quantity_reserved'=>0,'inbound_at'=>now()->subDays(3)]);
    $assignments[] = ['order_item_id'=>$item->id,'source_code'=>$warehouse];
}
app(FulfillmentAllocationSubmissionService::class)->submitManual($order, $assignments, 'Synthetic R5 first allocation', $actor, true);
$order->refresh();
app(\App\Modules\Orders\Application\OrderWorkflowStatusService::class)->set($order,'pending_allocation',$order->workflow_revision,$actor);
$order->forceFill(['domestic_reject_reason'=>'模拟：原分仓方案失败','domestic_rejected_by'=>$actor->id,'domestic_rejected_at'=>now()])->save();
echo "R5_FIXTURE_PASS: synthetic split A/B only\n";
