<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Finance\Application\FxReferenceSettingsService;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Application\PackagingOptimizationQuoteComparison;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PackagingOptimizationQuoteComparisonTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_supplier_source_draft_is_reused_without_activation(): void
    {
        $dir=getenv('BYL_RATE_SOURCE_DIR');
        if (!$dir || !is_dir($dir)) $this->markTestSkipped('A private source directory not present');
        $files=glob($dir.'/司沃康报价*.xlsx');
        self::assertCount(1,$files);
        \Illuminate\Support\Facades\Storage::fake('local');
        $file=new \Illuminate\Http\UploadedFile($files[0],basename($files[0]),null,null,true);
        $actor=User::factory()->create();
        $import=app(\App\Modules\Logistics\Application\LogisticsRateDraftService::class);
        $preview=$import->preview($file);
        $id=$import->save($file,$preview['sha256'],$preview['previous_version_id'],'2026-11-23',$actor->id)['version_id'];
        $route=['version_id'=>$id,'warehouse_code'=>'FACTORY','carrier'=>'SPEEDA','service'=>'sd_standard_special','country'=>'US','postal_code'=>'10001','as_of'=>'2026-11-27','handling_policy'=>'supplier'];
        $p=$this->package(0.08,[15,10,1]);
        $service=app(PackagingOptimizationQuoteComparison::class);
        $r=$service->compare($route,[$p],['carton'=>[$p]],true);
        self::assertSame('draft_estimate',$r['baseline']['status']);
        self::assertEqualsWithDelta(37.72,$r['baseline']['reference_native_totals']['CNY'],0.000001);
        self::assertSame(0.0,$r['candidates']['carton']['reference_transport_saving_cny']);
        self::assertFalse($r['candidates']['carton']['eligible_for_ranking']);
        self::assertNull($r['candidates']['carton']['logistics_saving_cny']);
        self::assertSame('rate_version_not_approved',$service->compare($route,[$p],['carton'=>[$p]])['baseline']['reason']);
        $this->assertDatabaseHas('logistics_rate_versions',['id'=>$id,'status'=>'draft']);
        $this->assertDatabaseCount('orders',0);
    }

    public function test_verified_single_item_route_uses_approved_a4_quote_for_monthly_projection(): void
    {
        $route=$this->route();
        DB::table('logistics_rate_versions')->where('id',$route['version_id'])->update(['status'=>'approved','approved_by'=>$route['actor_id'],'approved_at'=>now()]);
        $p=\App\Modules\Products\Infrastructure\Models\Product::create(['sku'=>'SYNTH-RANK','name'=>'Synthetic']);
        $w=\App\Modules\Inventory\Infrastructure\Models\Warehouse::query()->where('code','FACTORY')->firstOrFail();
        $o=DB::table('orders')->insertGetId(['internal_order_number'=>'SYNTH-RANK-1','source_type'=>'woocommerce','order_kind'=>'customer','order_date'=>'2026-10-01','order_status'=>'shipped']);
        $i=DB::table('order_items')->insertGetId(['order_id'=>$o,'product_id'=>$p->id,'product_summary'=>'Synthetic','sku'=>$p->sku,'quantity'=>1,'unit_price'=>10,'line_revenue'=>10]);
        DB::table('order_addresses')->insert(['order_id'=>$o,'recipient_name'=>'Synthetic','phone'=>'000','country'=>'US','province_state'=>'NY','city'=>'Test','address_line_1'=>'Test','postal_code'=>'10001']);
        DB::table('order_financials')->insert(['order_id'=>$o,'paid_at'=>'2026-10-01','provider_order_status'=>'completed','refund_amount'=>0]);
        $sid=DB::table('shipments')->insertGetId(['order_id'=>$o,'warehouse_id'=>$w->id,'shipment_status'=>'shipped','carrier'=>'TEST','service'=>'test','tracking_number'=>'SYNTH-RANK-1','shipped_at'=>'2026-10-02']);
        DB::table('shipment_item_packaging_snapshots')->insert(['shipment_id'=>$sid,'order_item_id'=>$i,'product_id'=>$p->id,'packaging_length_cm'=>39,'packaging_width_cm'=>25.5,'packaging_height_cm'=>18.7,'packaged_weight_kg'=>2.232,'captured_at'=>'2026-10-01']);
        $r=\App\Modules\Inventory\Infrastructure\Models\PackagingMeasurementRecord::create(['product_id'=>$p->id,'status'=>'approved','packaging_method_key'=>'carton','created_by'=>$route['actor_id'],'reviewed_by'=>$route['actor_id'],'reviewed_at'=>now(),'packaging_length_cm'=>39,'packaging_width_cm'=>25.5,'packaging_height_cm'=>13.4,'packaged_weight_kg'=>2.232,'measured_at'=>now()]);
        self::assertSame(0,$r->media()->count());
        $end=\Carbon\CarbonImmutable::parse('2026-11-27');
        $sample=app(\App\Modules\Inventory\Application\PackagingOptimizationRouteSamples::class)->forProduct($p->id,$end);
        $key=array_key_first($sample['cohorts']);
        $svc=app(\App\Modules\Inventory\Application\PackagingOptimizationHistoricalComparison::class);
        $proof=[$key=>['parcel_identity_verified'=>true,'candidate_protection_verified'=>true,'eligibility_confirmed'=>true,'evidence_reference'=>'Synthetic verified single parcel test','version_id'=>$route['version_id'],'handling_policy'=>'supplier']];
        $result=$svc->project($p->id,$r->id,$end,'2026-11-27',$proof);
        self::assertTrue($result['projection']['eligible_for_ranking']);
        self::assertSame(3.33,$result['projection']['monthly_logistics_saving_cny']);
        self::assertSame(1,$result['projection']['covered_shipments']);
        self::assertSame($route['version_id'],$result['cohorts'][0]['version_id']);
        self::assertFalse($svc->project($p->id,$r->id,$end,'2026-11-27',[])['projection']['eligible_for_ranking']);
        self::assertSame('approved',$r->fresh()->status);
        $this->assertDatabaseCount('shipments',1);
    }

    private function route(): array
    {
        $actor = User::factory()->create();
        $source = DB::table('logistics_rate_source_files')->insertGetId(['sha256'=>str_repeat('a',64),'original_filename'=>'synthetic.xlsx','private_storage_path'=>'test','mime_type'=>'test','byte_size'=>1]);
        $book = DB::table('logistics_rate_books')->insertGetId(['key'=>'synthetic','supplier_name'=>'Synthetic','display_name'=>'Synthetic']);
        $id = DB::table('logistics_rate_versions')->insertGetId(['book_id'=>$book,'source_file_id'=>$source,'version_number'=>1,'status'=>'draft','effective_from'=>'2026-01-01','approved_by'=>null]);
        $component = DB::table('logistics_rate_components')->insertGetId(['version_id'=>$id,'component_key'=>'transport','warehouse_code'=>'FACTORY','carrier'=>'TEST','service'=>'test','destination_country'=>'US','component_type'=>'transport','currency'=>'CNY','weight_unit'=>'kg','pricing_mode'=>'fixed_band','rule_spec'=>json_encode(['basis'=>'max','dim_divisor'=>6000,'volume_unit'=>'cm3_kg','increment'=>1,'coverage_complete'=>true,'handling_included'=>true]),'source_sheet'=>'Fixture','source_cells'=>'A1:B2','review_status'=>'verified']);
        foreach ([[0,2,10],[2,3,20],[3,4,30],[4,10,40]] as $i=>$band) {
            DB::table('logistics_rate_bands')->insert(['component_id'=>$component,'sequence'=>$i+1,'min_weight'=>$band[0],'max_weight'=>$band[1],'min_inclusive'=>false,'max_inclusive'=>true,'fixed_amount'=>$band[2],'source_cells'=>'A'.($i+1)]);
        }
        return ['version_id'=>$id,'warehouse_code'=>'FACTORY','carrier'=>'TEST','service'=>'test','country'=>'US','postal_code'=>'10001','as_of'=>'2026-11-27','handling_policy'=>'supplier','actor_id'=>$actor->id];
    }

    private function package(float $weight, array $dims, array $items=[10=>1], string $basis='measured_packaged', string $eligibility='confirmed'): array
    {
        return ['items'=>$items,'weight_kg'=>$weight,'dimensions_cm'=>$dims,'weight_basis'=>$basis,'eligibility'=>$eligibility];
    }

    public function test_real_a4_engine_keeps_draft_and_formal_comparison_separate_and_checks_composition(): void
    {
        $route=$this->route();
        $service=app(PackagingOptimizationQuoteComparison::class);
        $old=[$this->package(2.232,[39,25.5,18.7])];
        $candidate=['carton'=>[$this->package(2.232,[39,25.5,13.4])],'direct_mailer'=>[$this->package(1,[20,20,10],[11=>1])]];
        $draft=$service->compare($route,$old,$candidate,true);
        self::assertSame('draft_estimate',$draft['baseline']['status'],json_encode($draft));
        self::assertFalse($draft['candidates']['carton']['eligible_for_ranking']);
        self::assertNull($draft['candidates']['carton']['logistics_saving_cny']);
        self::assertSame(10.0,$draft['candidates']['carton']['reference_transport_saving_cny']);
        self::assertSame('different_item_composition',$draft['candidates']['direct_mailer']['quote']['reason']);
        self::assertSame('rate_version_not_approved',$service->compare($route,$old,$candidate)['baseline']['reason']);
        DB::table('logistics_rate_versions')->where('id',$route['version_id'])->update(['status'=>'approved','approved_by'=>$route['actor_id'],'approved_at'=>now()]);
        $formal=$service->compare($route,$old,$candidate);
        self::assertSame('ready',$formal['baseline']['status']);
        self::assertSame(10.0,$formal['candidates']['carton']['logistics_saving_cny']);
        self::assertTrue($formal['candidates']['carton']['eligible_for_ranking']);
        self::assertNull($formal['candidates']['carton']['reference_transport_saving_cny']);
        self::assertSame('rate_scope_missing',$service->compare([...$route,'warehouse_code'=>'OTHER'],$old,$candidate)['baseline']['reason']);
        self::assertSame('draft_rate_version_required',$service->compare($route,$old,$candidate,true)['baseline']['reason']);
        $target=$service->economicHeightTarget($route,$old[0],'carton',[13.4,18.6,17.9]);
        self::assertSame('candidate',$target['status']);
        self::assertSame(17.9,$target['target_height_cm']);
        self::assertFalse($target['eligible_for_ranking']);
        self::assertFalse($target['protection_verified']);
        self::assertSame('eligibility_unconfirmed',$service->compare($route,[$this->package(2,[20,20,10],[10=>1],'measured_packaged','unknown')],$candidate)['baseline']['reason']);
        $this->assertDatabaseCount('orders',0);
        $this->assertDatabaseCount('packaging_measurement_records',0);
    }
}
