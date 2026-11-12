<?php

declare(strict_types=1);

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Models\OverseaWarehouseReview;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderSourceType;
use App\Modules\Orders\Application\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

function denyVisual(string $message): never
{
    fwrite(STDERR, 'Reject visual fixture denied: '.$message.PHP_EOL);
    exit(1);
}

$repo = realpath(dirname(__DIR__, 3));
$root = realpath((string) getenv('BYL_E2E_RUN_ROOT'));
$db = realpath((string) getenv('DB_DATABASE'));
$runId = preg_replace('/[^a-z0-9]/i', '', (string) getenv('BYL_E2E_RUN_ID'));
if (! $repo || ! $root || ! $db || str_starts_with($root, $repo.DIRECTORY_SEPARATOR)) denyVisual('workspace');
if (! str_starts_with($root, realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'byl-erp-e2e-')) denyVisual('run root');
if (dirname($db) !== $root.DIRECTORY_SEPARATOR.'database' || basename($db) !== 'e2e.sqlite') denyVisual('database');
if (getenv('APP_ENV') !== 'testing' || getenv('BYL_E2E_DISPOSABLE') !== 'true' ||
    getenv('DB_CONNECTION') !== 'sqlite' || getenv('ERP_LOCAL_ADMIN_BYPASS') !== 'false') denyVisual('env');
if (! $runId || strlen($runId) < 20) denyVisual('run id');
require $repo.'/vendor/autoload.php';
$app = require $repo.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'sqlite' ||
    realpath((string) config('database.connections.sqlite.database')) !== $db) denyVisual('effective database');
if ($app->configurationIsCached() || $app->routesAreCached()) denyVisual('cache');

$owner = User::query()->where('email', "authorized-{$runId}@byl-e2e.invalid")->firstOrFail();
$product = Product::query()->where('sku', 'E2E-SKU-0001')->firstOrFail();
$domestic = Order::query()->where('erp_order_id', '007-1110-c99992')->firstOrFail();
$overseas = Order::query()->where('erp_order_id', '007-1110-c99990')->firstOrFail();
if ($domestic->domestic_reject_reason || OverseaWarehouseReview::query()->where('order_id', $overseas->id)->exists()) denyVisual('fixture already marked');
DB::transaction(static function () use ($owner, $product, $domestic, $overseas): void {
    // Synthetic-only rejections: no endpoints or actual production orders.
    $domestic->shipment()->update(['warehouse_id'=>null, 'warehouse_selection_source'=>null]);
    $domestic->forceFill([
        'domestic_reject_reason'=>'国内商务驳回：收件地址需核对（模拟）',
        'domestic_rejected_by'=>$owner->id,
        'domestic_rejected_at'=>now()->subMinutes(5),
        'order_date'=>now()->subDays(2)->toDateString(),
    ])->save();
    $overseas->forceFill(['order_date'=>now()->subDays(3)->toDateString()])->save();
    OverseaWarehouseReview::query()->create([
        'order_id'=>$overseas->id,
        'status'=>'rejected',
        'note'=>'海外仓驳回：仓库地址信息不完整（模拟）',
        'reviewed_by'=>$owner->id,
        'reviewed_at'=>now()->subMinutes(2),
    ]);
    // Populate over one server page so a per-page counter would hide both
    // synthetic rejected orders and fail the visual/business acceptance.
    for ($n=1; $n<=23; $n++) {
        $order = Order::query()->create([
            'internal_order_number'=>sprintf('INT-VISUAL-%03d',$n),
            'erp_order_id'=>sprintf('007-VISUAL-%03d',$n),
            'source_type'=>OrderSourceType::Manual,
            'order_kind'=>OrderKind::Customer,
            'external_order_number'=>sprintf('E2E-VISUAL-%03d',$n),
            'order_date'=>now()->addDay()->toDateString(),
            'owner_id'=>$owner->id,
            'order_status'=>OrderStatus::PendingShipment,
            'currency'=>'USD',
        ]);
        $order->items()->create([
            'product_id'=>$product->id,'sku'=>$product->sku,
            'product_summary'=>'E2E Visual Product','quantity'=>1,
            'unit_price'=>'10.00','line_revenue'=>'10.00',
        ]);
        $order->address()->create([
            'recipient_name'=>'E2E Visual Normal '.$n,
            'country'=>'US','province_state'=>'CA','city'=>'Los Angeles',
            'address_line_1'=>'123 Synthetic Road','postal_code'=>'90001',
        ]);
    }
});
$rejection = app(\App\Modules\Logistics\Application\AllocationRejectionReadModel::class);
$count = $rejection->onlyRejected(app(\App\Modules\Logistics\Application\LogisticsStatusSummary::class)
    ->pendingAllocationQuery())->count();
if ($count !== 2) denyVisual('unexpected rejection count '.$count);
fwrite(STDOUT, "REJECTION_VISUAL_FIXTURES_PASS: 2 rejected, 23 normals, isolated SQLite\n");
