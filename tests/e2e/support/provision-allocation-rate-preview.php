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
    $confirmed=Order::where('erp_order_id',$id)->firstOrFail();
    $unknown=Order::where('erp_order_id',$missing)->firstOrFail();
    $cost=$confirmed->fulfillmentAllocation?->plan_snapshot['cost_confirmation']??[];
    $skip=$unknown->fulfillmentAllocation?->plan_snapshot['cost_confirmation']??[];
    if(($cost['confirmation_status']??null)!=='confirmed'||count($cost['groups']??[])!==2||($cost['known_subtotal_cny']??0)<=0
        ||($skip['confirmation_status']??null)!=='skipped_unavailable'||($skip['total_cny']??null)!==null
        ||Order::whereIn('erp_order_id',[$id,$missing])->count()!==2||DB::table('fulfillment_allocations')->count()!==2
        ||DB::table('logistics_rate_versions')->where('status','approved')->count()!==0
        ||DB::table('logistics_rate_versions')->count()!==4)
        throw new RuntimeException('Read-only database contract failed');
    echo "RATE_PREVIEW_DB_READBACK_PASS\n";exit;
}
$runId=preg_replace('/[^a-z0-9]/i','',(string)getenv('BYL_E2E_RUN_ID'));
$actor=User::where('email','logistics-operator-'.$runId.'@byl-e2e.invalid')->firstOrFail();
$sourceDir=(string)getenv('BYL_RATE_SOURCE_DIR');
$manifest=[
    'tianyun_de'=>['2025-天运-德国-邮好运德国海外仓服务报价（生效时间：20250505）.xlsx','ee170cc914dd100352e772e254a7e7483e9116647a58dc33a00edd37094b207a','2025-05-05',34,46,0],
    'speeda_us'=>['2026-速达美东仓-美国标准海外仓报价-2026.05.01xlsx(1).xlsx','c24c2b403a5f3955a274950757cbdae9d1f0df06db83921a914474cc59cd0f96','2026-05-01',29,1854,6944],
    'speeda_sd'=>['司沃康报价20261123新增SD标准-特货.xlsx','e7edd7f11d32773a9761122c5a08bebac8128cbf22075cc641d475e4e96caef6','2026-11-23',389,875,28332],
    'territory_evidence'=>['非欧盟地区汇总表.xls','e36a1a79ba41002a95ecc0e599d0edc2ba8f7113df8e6e55836a3617faf0bcd8',null,0,0,0],
];
$service=app(LogisticsRateDraftService::class);
foreach($manifest as $key=>[$name,$hash,$effective,$components,$bands,$zones]){
    $source=$sourceDir.'/'.$name;
    if(!is_file($source)||hash_file('sha256',$source)!==$hash)throw new RuntimeException('Verified original source required: '.$key);
    $file=new UploadedFile($source,basename($source),null,null,true);
    $preview=$service->preview($file);
    if($preview['book_key']!==$key||$preview['component_count']!==$components||$preview['band_count']!==$bands||$preview['zone_count']!==$zones)
        throw new RuntimeException('Unexpected original source: '.$key);
    $service->save($file,$preview['sha256'],$preview['previous_version_id'],$effective,$actor->id);
    echo 'SOURCE_DRAFT_PASS '.$key.' '.$components.' '.$bands.' '.$zones.PHP_EOL;
}
app(FxReferenceSettingsService::class)->save(['EUR'=>8,'USD'=>7.5],['EUR'=>false,'USD'=>false],null);
foreach([$id,$missing] as $i=>$orderNumber){
    $o=Order::create(['internal_order_number'=>'E2E-RATE-'.$i,'erp_order_id'=>$orderNumber,'external_order_number'=>'E2E-RATE-'.$i,
        'source_type'=>'manual','order_kind'=>'customer','order_date'=>now()->toDateString(),'order_status'=>'pending_shipment','currency'=>'USD','owner_id'=>$actor->id]);
    $o->address()->create(['recipient_name'=>'Synthetic Rate Recipient','country'=>'DE','city'=>'Berlin','postal_code'=>'10115','address_line_1'=>'Synthetic Road 1','phone'=>'10000000000']);
    for($j=0;$j<($i===0?2:1);$j++){
    $p=Product::create(['name'=>'Synthetic Rate Product '.$i.'-'.$j,'sku'=>'E2E-RATE-P'.$i.'-'.$j,'source_type'=>'manual','product_type'=>'simple','is_active'=>true]);
    $o->items()->create(['product_id'=>$p->id,'sku'=>$p->sku,'product_summary'=>$p->name,'quantity'=>$i===0?1:2,'unit_price'=>10,'line_revenue'=>$i===0?10:20]);
    if($i===0){
        $r=PackagingMeasurementRecord::create(['product_id'=>$p->id,'status'=>'approved','packaging_method_key'=>'bubble_wrap_cut','packaged_weight_kg'=>0.5,
            'packaging_length_cm'=>20,'packaging_width_cm'=>15,'packaging_height_cm'=>5,'measured_at'=>now(),'created_by'=>$actor->id,'reviewed_by'=>$actor->id,'reviewed_at'=>now()]);
        ProductPackagingProfile::create(['product_id'=>$p->id,'preset_key'=>'bubble_wrap_cut','is_active'=>true,'current_measurement_record_id'=>$r->id]);
    }
    }
}
echo "RATE_PREVIEW_FIXTURE_PASS: all four original source drafts plus two synthetic orders\n";
