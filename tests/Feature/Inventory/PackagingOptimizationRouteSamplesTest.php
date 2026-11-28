<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Application\PackagingOptimizationRouteSamples;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PackagingOptimizationRouteSamplesTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_single_item_history_is_grouped_and_ambiguous_orders_excluded(): void
    {
        $p=Product::create(['sku'=>'HIST-ONE','name'=>'Synthetic']);
        $w=Warehouse::create(['code'=>'TEST-WH','slug'=>'test-wh','name'=>'Synthetic','country_code'=>'US','type'=>'overseas']);
        $add=function(string $ref,int $qty=1,bool $snapshot=true,?int $parent=null,int $refund=0) use($p,$w) {
            $o=DB::table('orders')->insertGetId(['internal_order_number'=>$ref,'source_type'=>'woocommerce','order_kind'=>'customer','order_date'=>'2026-10-01','order_status'=>'shipped','fulfillment_parent_id'=>$parent]);
            $i=DB::table('order_items')->insertGetId(['order_id'=>$o,'product_id'=>$p->id,'product_summary'=>'Synthetic','sku'=>$p->sku,'quantity'=>$qty,'unit_price'=>10,'line_revenue'=>10*$qty]);
            DB::table('order_addresses')->insert(['order_id'=>$o,'recipient_name'=>'Synthetic','phone'=>'000','country'=>'US','province_state'=>'NY','city'=>'Test','address_line_1'=>'Test','postal_code'=>'10001']);
            DB::table('order_financials')->insert(['order_id'=>$o,'paid_at'=>'2026-10-01','provider_order_status'=>'completed','refund_amount'=>$refund]);
            $s=DB::table('shipments')->insertGetId(['order_id'=>$o,'warehouse_id'=>$w->id,'shipment_status'=>'shipped','carrier'=>'TEST','service'=>'test','tracking_number'=>$ref,'shipped_at'=>'2026-10-02']);
            if($snapshot) DB::table('shipment_item_packaging_snapshots')->insert(['shipment_id'=>$s,'order_item_id'=>$i,'product_id'=>$p->id,'packaging_length_cm'=>39,'packaging_width_cm'=>25.5,'packaging_height_cm'=>18.7,'packaged_weight_kg'=>2.232,'captured_at'=>'2026-10-01']);
            return $o;
        };
        $add('valid1'); $add('valid2'); $add('multi',2); $add('missing',1,false); $add('refund',1,true,null,1);
        $parent=$add('parent'); $add('child',1,true,$parent);
        $r=app(PackagingOptimizationRouteSamples::class)->forProduct($p->id,CarbonImmutable::parse('2026-11-27'),90);
        self::assertSame(7,$r['observed_shipments']);
        self::assertSame(2,$r['candidate_shipments']);
        self::assertSame(5,$r['excluded_shipments']);
        self::assertCount(1,$r['cohorts']);
        $c=reset($r['cohorts']);
        self::assertSame(2,$c['shipment_count']);
        self::assertFalse($c['parcel_identity_verified']);
        self::assertSame('10001',$c['route']['postal_code']);
        self::assertSame(2.232,$c['snapshot']['weight_kg']);
        $projection=app(\App\Modules\Inventory\Application\PackagingOptimizationHistoricalComparison::class)
            ->project($p->id,999,CarbonImmutable::parse('2026-11-27'),'2026-11-27',[],90);
        self::assertFalse($projection['candidate_evidence_complete']);
        self::assertNull($projection['projection']['monthly_logistics_saving_cny']);
        self::assertFalse($projection['projection']['eligible_for_ranking']);
        self::assertSame(7,$projection['projection']['excluded_shipments']);
    }
}
