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
if (getenv('BYL_PACKAGING_OWNER_UAT')==='1') {
    $admin=User::query()->where('email','admin@local.test')->firstOrFail();
    $base=PackagingMeasurementRecord::create(['product_id'=>$p->id,'status'=>'approved','packaging_method_key'=>'carton','created_by'=>$admin->id,
        'packaging_length_cm'=>39,'packaging_width_cm'=>25.5,'packaging_height_cm'=>18.7,'packaged_weight_kg'=>2.232,'measured_at'=>now()]);
    \App\Modules\Products\Infrastructure\Models\ProductPackagingProfile::create(['product_id'=>$p->id,'preset_key'=>'carton','current_measurement_record_id'=>$base->id,'is_active'=>true]);
    PackagingMeasurementRecord::create(['product_id'=>$p->id,'status'=>'approved','packaging_method_key'=>'document_bubble_mailer','created_by'=>$admin->id,'packaging_length_cm'=>39,'packaging_width_cm'=>25.5,'packaging_height_cm'=>13.4,'packaged_weight_kg'=>2.232,'measured_at'=>now()]);
    Product::create(['sku'=>'UAT-PACKAGE-002','name'=>'待补录测量（测试）','is_active'=>true]);
    $source=DB::table('logistics_rate_source_files')->insertGetId(['sha256'=>hash('sha256','packaging-owner-uat-fixture'),'original_filename'=>'SYNTHETIC-UAT-NOT-REAL.xlsx','private_storage_path'=>'synthetic','mime_type'=>'test','byte_size'=>1]);
    $book=DB::table('logistics_rate_books')->insertGetId(['key'=>'synthetic-packaging','supplier_name'=>'Synthetic UAT','display_name'=>'Synthetic UAT']);
    $version=DB::table('logistics_rate_versions')->insertGetId(['book_id'=>$book,'source_file_id'=>$source,'version_number'=>1,'status'=>'draft','effective_from'=>'2026-01-01']);
    $component=DB::table('logistics_rate_components')->insertGetId(['version_id'=>$version,'component_key'=>'transport','warehouse_code'=>'FACTORY','carrier'=>'UAT-TEST','service'=>'synthetic','destination_country'=>'US','component_type'=>'transport','currency'=>'CNY','weight_unit'=>'kg','pricing_mode'=>'fixed_band','rule_spec'=>json_encode(['basis'=>'max','dim_divisor'=>6000,'volume_unit'=>'cm3_kg','increment'=>1,'coverage_complete'=>true,'handling_included'=>true]),'source_sheet'=>'SYNTHETIC','source_cells'=>'A1:B4','review_status'=>'verified']);
    foreach ([[0,2,10],[2,3,20],[3,4,30],[4,10,40]] as $i=>$band) DB::table('logistics_rate_bands')->insert(['component_id'=>$component,'sequence'=>$i+1,'min_weight'=>$band[0],'max_weight'=>$band[1],'min_inclusive'=>false,'max_inclusive'=>true,'fixed_amount'=>$band[2],'source_cells'=>'A'.($i+1)]);
    echo 'OWNER_FIXTURE_READY';
} else echo $r->id;
