<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Infrastructure\Models\PackagingMeasurementRecord;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require dirname(__DIR__,3).'/vendor/autoload.php';
$app=require dirname(__DIR__,3).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$root=realpath((string)getenv('BYL_E2E_RUN_ROOT'));
$db=realpath((string)getenv('DB_DATABASE'));
if (!$app->environment('testing') || getenv('BYL_E2E_DISPOSABLE')!=='true' || !$root || !$db
    || !str_starts_with($db,$root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR)
    || config('database.default')!=='sqlite' || realpath(config('database.connections.sqlite.database'))!==$db
    || DB::connection()->getDriverName()!=='sqlite' || config('erp_local.admin_bypass.enabled')) {
    throw new RuntimeException('Disposable packaging UAT safety gate failed');
}
$make=function(string $role,array $permissions): User {
    $r=Role::create(['key'=>'uat-packaging-'.$role,'label'=>'Synthetic '.$role]);
    foreach($permissions as $key) $r->permissions()->attach(Permission::firstOrCreate(['key'=>$key],['label'=>$key])->id);
    $u=User::create(['name'=>'Synthetic '.$role,'email'=>$role.'@packaging.invalid','password'=>Hash::make(getenv('BYL_E2E_ACTIVE_PASSWORD')),'is_active'=>true]);
    $u->roles()->attach($r);
    return $u;
};
$factory=$make('factory',['inventory.packaging.submit']);
$make('reviewer',['dashboard.view','inventory.view','inventory.packaging.review']);
$p=Product::create(['sku'=>'UAT-PACKAGE-001','name'=>'Synthetic packaging sample']);
$r=PackagingMeasurementRecord::create(['product_id'=>$p->id,'status'=>'rejected','packaging_method_key'=>'carton','created_by'=>$factory->id,
    'packaging_length_cm'=>39,'packaging_width_cm'=>25.5,'packaging_height_cm'=>18.7,'packaged_weight_kg'=>2.232,
    'review_note'=>'Synthetic rejected evidence','measured_at'=>now()]);
echo $r->id;
