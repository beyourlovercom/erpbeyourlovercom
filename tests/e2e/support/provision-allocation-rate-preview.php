<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Infrastructure\Models\PackagingMeasurementRecord;
use App\Modules\Logistics\Application\LogisticsRateDraftService;
use App\Modules\Finance\Application\FxReferenceSettingsService;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductPackagingProfile;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

$repo=realpath(dirname(__DIR__,3));
$root=realpath((string)getenv('BYL_E2E_RUN_ROOT'));$db=realpath((string)getenv('DB_DATABASE'));
if(!$root||!$db||!str_starts_with($root,realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'byl-erp-e2e-')
    ||$db!==$root.'/database/e2e.sqlite'||getenv('APP_ENV')!=='testing'||getenv('BYL_E2E_DISPOSABLE')!=='true'
    ||getenv('DB_CONNECTION')!=='sqlite'||getenv('ERP_LOCAL_ADMIN_BYPASS')!=='false')throw new RuntimeException('Disposable database required');
require $repo.'/vendor/autoload.php';$app=require $repo.'/bootstrap/app.php';$app->make(Kernel::class)->bootstrap();
if(!$app->environment('testing')||config('database.default')!=='sqlite'||realpath((string)config('database.connections.sqlite.database'))!==$db
    ||$app->configurationIsCached()||$app->routesAreCached())throw new RuntimeException('Effective runtime not isolated');
$id='C9900021-007';$missing='C9900022-007';
if(in_array('--verify',$argv,true)){
    if(Order::whereIn('erp_order_id',[$id,$missing])->count()!==2||DB::table('fulfillment_allocations')->count()!==0
        ||DB::table('logistics_rate_versions')->where('status','approved')->count()!==0
        ||DB::table('logistics_rate_versions')->count()!==1)
        throw new RuntimeException('Read-only database contract failed');
    echo "RATE_PREVIEW_DB_READBACK_PASS\n";exit;
}
$runId=preg_replace('/[^a-z0-9]/i','',(string)getenv('BYL_E2E_RUN_ID'));
$actor=User::where('email','logistics-operator-'.$runId.'@byl-e2e.invalid')->firstOrFail();
$source=(string)getenv('BYL_RATE_DE_SOURCE');
if(!is_file($source)||hash_file('sha256',$source)!=='ee170cc914dd100352e772e254a7e7483e9116647a58dc33a00edd37094b207a')throw new RuntimeException('Verified original DE source required');
$file=new UploadedFile($source,basename($source),null,null,true);
$service=app(LogisticsRateDraftService::class);$preview=$service->preview($file);
$result=$service->save($file,$preview['sha256'],$preview['previous_version_id'],'2025-05-05',$actor->id);
if($preview['component_count']!==34||$preview['band_count']!==46)throw new RuntimeException('Unexpected actual rate source');
app(FxReferenceSettingsService::class)->save(['EUR'=>8,'USD'=>7.5],['EUR'=>false,'USD'=>false],null);
foreach([$id,$missing] as $i=>$orderNumber){
    $o=Order::create(['internal_order_number'=>'E2E-RATE-'.$i,'erp_order_id'=>$orderNumber,'external_order_number'=>'E2E-RATE-'.$i,
        'source_type'=>'manual','order_kind'=>'customer','order_date'=>now()->toDateString(),'order_status'=>'pending_shipment','currency'=>'USD','owner_id'=>$actor->id]);
    $o->address()->create(['recipient_name'=>'Synthetic Rate Recipient','country'=>'DE','city'=>'Berlin','postal_code'=>'10115','address_line_1'=>'Synthetic Road 1','phone'=>'10000000000']);
    $p=Product::create(['name'=>'Synthetic Rate Product '.$i,'sku'=>'E2E-RATE-P'.$i,'source_type'=>'manual','product_type'=>'simple','is_active'=>true]);
    $o->items()->create(['product_id'=>$p->id,'sku'=>$p->sku,'product_summary'=>$p->name,'quantity'=>2,'unit_price'=>10,'line_revenue'=>20]);
    if($i===0){
        $r=PackagingMeasurementRecord::create(['product_id'=>$p->id,'status'=>'approved','packaging_method_key'=>'bubble_wrap_cut','packaged_weight_kg'=>0.5,
            'packaging_length_cm'=>20,'packaging_width_cm'=>15,'packaging_height_cm'=>5,'measured_at'=>now(),'created_by'=>$actor->id,'reviewed_by'=>$actor->id,'reviewed_at'=>now()]);
        ProductPackagingProfile::create(['product_id'=>$p->id,'preset_key'=>'bubble_wrap_cut','is_active'=>true,'current_measurement_record_id'=>$r->id]);
    }
}
echo "RATE_PREVIEW_FIXTURE_PASS: original DE draft plus two synthetic orders\n";
