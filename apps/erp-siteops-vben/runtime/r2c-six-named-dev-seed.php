<?php
declare(strict_types=1);

// Isolated ERP2 R2-C fixture only. Never run against Production or the original R2-C Dev DB.
$root = getenv('ERP_R2C_LARAVEL_DEV_ROOT') ?: '';
$db = getenv('ERP_R2C_STAFF_DEV_DB') ?: '';
if (!is_dir($root) || !is_file($root.'/bootstrap/app.php') || !is_file($db)
    || basename($db) !== 'erp-siteops-r2c-staff-20261116.sqlite'
    || !str_starts_with(realpath($db) ?: '', '/Users/a/ai/uat/')) {
    throw new RuntimeException('R2C_NAMED_UAT_ISOLATION_GUARD');
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (config('app.env') !== 'local'
    || config('database.default') !== 'sqlite'
    || config('database.connections.sqlite.database') !== realpath($db)) {
    throw new RuntimeException('R2C_NAMED_UAT_LOCAL_SQLITE_ONLY');
}

use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$staff = [
  'Vita'  => ['email'=>'vita-r2c@local.test','role'=>'r2c_vita_ops','perms'=>['dashboard.view','orders.view','orders.edit','logistics.view','sync.view','sync.operate']],
  'Liang' => ['email'=>'liang-r2c@local.test','role'=>'r2c_liang_domestic','perms'=>['dashboard.view','orders.view','logistics.view','logistics.operate','domestic.review']],
  'Dede'  => ['email'=>'dede-r2c@local.test','role'=>'r2c_dede_overseas','perms'=>['dashboard.view','orders.view','logistics.view','logistics.operate','oversea.review']],
  'Emma'  => ['email'=>'emma-r2c@local.test','role'=>'r2c_emma_kol','perms'=>['dashboard.view','orders.view','kol.view','kol.operate']],
  'Guts'  => ['email'=>'guts-r2c@local.test','role'=>'r2c_guts_kol_manager','perms'=>['dashboard.view','orders.view','kol.view','kol.operate','kol.views.manage']],
  'Hiko'  => ['email'=>'hiko-r2c@local.test','role'=>'r2c_hiko_kol','perms'=>['dashboard.view','orders.view','kol.view','kol.operate']],
];
$existingUsers = DB::table('users')->count();
$existingOrders = DB::table('orders')->count();
if ($existingUsers === 10 && $existingOrders === 129
    && DB::table('users')->whereIn('email', array_column($staff, 'email'))->count() === 6) {
    echo "R2C_NAMED_DEV_FIXTURE_ALREADY_READY users=10 orders=129\n";
    exit(0);
}
if ($existingUsers !== 4 || $existingOrders !== 126
    || DB::table('users')->where('email','not like','%@local.invalid')->exists()) {
    throw new RuntimeException('R2C_NAMED_DEV_FIXTURE_NOT_PRISTINE');
}

DB::transaction(static function () use ($staff): void {
    $users = [];
    foreach ($staff as $name => $spec) {
        $role = Role::create(['key'=>$spec['role'],'label'=>'R2C isolated named Dev counterpart: '.$name]);
        foreach ($spec['perms'] as $key) {
            $permission = Permission::firstOrCreate(['key'=>$key],['label'=>$key]);
            $role->permissions()->attach($permission->id);
        }
        $user = User::create([
            'name'=>$name.' (R2C Dev only)',
            'email'=>$spec['email'],
            'password'=>Hash::make('password'),
            'is_active'=>true,
        ]);
        $user->roles()->attach($role->id);
        $users[$name] = $user->id;
    }
    foreach (['Emma','Guts','Hiko'] as $name) {
        $number = '007-R2C-'.strtoupper($name).'-001';
        $orderId = DB::table('orders')->insertGetId([
            'internal_order_number'=>$number,
            'external_order_number'=>'EX-R2C-'.strtoupper($name).'-001',
            'erp_order_id'=>$number,
            'source_type'=>'kol',
            'order_kind'=>'kol_fulfillment',
            'order_date'=>now()->toDateString(),
            'owner_id'=>$users[$name],
            'currency'=>'USD',
            'order_status'=>'pending_shipment',
            'created_at'=>now(),
            'updated_at'=>now(),
        ]);
        DB::table('order_items')->insert([
            'order_id'=>$orderId,
            'product_summary'=>'R2C isolated employee counterpart fixture',
            'sku'=>'TEST-R2C-NAMED-'.strtoupper($name),
            'quantity'=>1,
            'unit_price'=>1,
            'line_revenue'=>1,
            'created_at'=>now(),
            'updated_at'=>now(),
        ]);
    }
});
echo 'R2C_NAMED_DEV_FIXTURE_PASS users='.DB::table('users')->count().
  ' orders='.DB::table('orders')->count().
  ' only_dev=true password_scope=local_named_counterparts'."\n";
