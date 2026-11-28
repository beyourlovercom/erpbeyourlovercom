<?php

namespace Tests\Feature\Inventory;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Infrastructure\Models\PackagingMeasurementRecord;
use App\Modules\Products\Application\ProductLogisticsProjection;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductPackagingProfile;
use App\Modules\Products\Infrastructure\Models\ProductWeightEvidence;
use App\Modules\Products\Infrastructure\Models\ProductParameterSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ProductInventoryLogisticsTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): User
    {
        $u=User::factory()->create(['is_active'=>true]);
        $role=Role::create(['key'=>'test-'.uniqid(),'label'=>'Test']);
        foreach ($permissions as $permission) $role->permissions()->firstOrCreate(['key'=>$permission],['label'=>$permission]);
        $u->roles()->attach($role);
        return $u;
    }

    public function test_legacy_product_dimensions_and_reference_weight_never_become_shipping_truth(): void
    {
        $p=Product::create(['sku'=>'SIZE-A','name'=>'A','is_active'=>true,'length_cm'=>99,'width_cm'=>98,'height_cm'=>97,'shipping_weight_kg'=>3]);
        $r=app(ProductLogisticsProjection::class)->rows([$p->id])[0];
        $this->assertNull($r['shipping_dimensions']);
        $this->assertNull($r['shipping_weight_kg']);
        $this->assertNull($r['retail_dimensions']);
        $this->assertSame(3.0,$r['reference_weight_kg']);
        $this->assertNull($r['billable_weight_kg']);
        $this->assertSame('待测量',$r['shipping_status']);
        $this->assertStringNotContainsString('99×98×97',json_encode($r));
    }

    public function test_only_same_product_approved_current_record_is_shipping_standard(): void
    {
        $u=$this->userWith(['inventory.view','products.view']);
        $p=Product::create(['sku'=>'SIZE-B','name'=>'B','is_active'=>true]);
        $other=Product::create(['sku'=>'SIZE-C','name'=>'C','is_active'=>true]);
        $base=['created_by'=>$u->id,'packaging_method_key'=>'carton','packaging_length_cm'=>30,'packaging_width_cm'=>20,'packaging_height_cm'=>10,'packaged_weight_kg'=>0.75,'measured_at'=>now()];
        $approved=PackagingMeasurementRecord::create($base+['product_id'=>$p->id,'status'=>'approved']);
        $pending=PackagingMeasurementRecord::create($base+['product_id'=>$p->id,'status'=>'submitted']);
        $wrong=PackagingMeasurementRecord::create($base+['product_id'=>$other->id,'status'=>'approved']);
        $profile=ProductPackagingProfile::create(['product_id'=>$p->id,'current_measurement_record_id'=>$pending->id,'is_active'=>true]);
        $projection=app(ProductLogisticsProjection::class);
        $this->assertNull($projection->rows([$p->id])[0]['shipping_dimensions']);
        $profile->update(['current_measurement_record_id'=>$wrong->id]);
        $this->assertNull($projection->rows([$p->id])[0]['shipping_dimensions']);
        $profile->update(['current_measurement_record_id'=>$approved->id]);
        $r=$projection->rows([$p->id])[0];
        $this->assertSame('30×20×10',$r['shipping_dimensions']);
        $this->assertSame(6000.0,$r['shipping_volume_cm3']);
        $this->assertSame(0.75,$r['shipping_weight_kg']);
        $this->assertSame('已采纳',$r['shipping_status']);
        $sources=collect($projection->sources([$p->id]));
        $this->assertTrue($sources->firstWhere('id','pack-'.$approved->id)['current']);
        $this->assertFalse($sources->firstWhere('id','pack-'.$pending->id)['current']);
    }

    public function test_factory_dimensions_and_carton_are_not_shipping_dimensions(): void
    {
        $u=$this->userWith(['products.view']);
        $p=Product::create(['sku'=>'SIZE-FACTORY','name'=>'Factory','is_active'=>true]);
        $base=['product_id'=>$p->id,'created_by'=>$u->id,'accessories'=>[]];
        $record=ProductParameterSubmission::create($base+['status'=>'approved','reviewed_at'=>now(),'measurements'=>[
            'product_length_cm'=>99,'product_width_cm'=>98,'product_height_cm'=>97,
            'color_box_gross_kg'=>0.5,'package_length_cm'=>22,'package_width_cm'=>15,'package_height_cm'=>8,
            'carton_length_cm'=>60,'carton_width_cm'=>40,'carton_height_cm'=>40,'units_per_carton'=>10,'carton_gross_weight_kg'=>6,
        ]]);
        ProductParameterSubmission::create($base+['status'=>'submitted','measurements'=>['color_box_gross_kg'=>5]]);
        $projection=app(ProductLogisticsProjection::class);
        $r=$projection->rows([$p->id])[0];
        $this->assertSame(0.5,$r['retail_weight_kg']);
        $this->assertSame('22×15×8',$r['unclassified_package_dimensions']);
        $this->assertSame('60×40×40',$r['carton_dimensions']);
        $this->assertSame(0.096,$r['carton_cbm']);
        $this->assertNull($r['retail_dimensions']);
        $this->assertNull($r['shipping_dimensions']);
        $sources=collect($projection->sources([$p->id]));
        $this->assertTrue($sources->firstWhere('id','factory-'.$record->id.'-gross')['current']);
        $this->assertStringNotContainsString('99×98×97',json_encode($sources));
    }

    public function test_explicit_tencent_retail_weight_is_reference_not_shipping_truth(): void
    {
        $p=Product::create(['sku'=>'SIZE-TENCENT','name'=>'Imported','is_active'=>true,'shipping_weight_kg'=>0.7]);
        $e=ProductWeightEvidence::create(['product_id'=>$p->id,'weight_kind'=>'shipping_reference_weight','observed_weight_kg'=>0.7,'source_type'=>'other_documented','source_reference'=>'Tencent docs example','source_note'=>'腾讯文档毛重/彩盒KG；非新增实测','status'=>'adopted']);
        $p->update(['shipping_weight_evidence_id'=>$e->id]);
        $projection=app(ProductLogisticsProjection::class);
        $r=$projection->rows([$p->id])[0];
        $this->assertSame(0.7,$r['retail_weight_kg']);
        $this->assertStringContainsString('历史彩盒',$r['retail_weight_source']);
        $this->assertNull($r['shipping_weight_kg']);
        $this->assertNull($r['shipping_dimensions']);
        $this->assertSame('彩盒毛重（历史）',$projection->sources([$p->id])[0]['kind']);
    }

    public function test_versioned_rate_preview_uses_adopted_shipping_measurement_only_and_never_mutates_data(): void
    {
        $u=$this->userWith(['inventory.view','products.view','logistics.view']);
        $p=Product::create(['sku'=>'SIZE-QUOTE','name'=>'Quote','is_active'=>true,'length_cm'=>90,'width_cm'=>80,'height_cm'=>70,'shipping_weight_kg'=>9]);
        $base=['product_id'=>$p->id,'created_by'=>$u->id,'packaging_method_key'=>'carton','packaging_length_cm'=>30,'packaging_width_cm'=>20,'packaging_height_cm'=>10,'packaged_weight_kg'=>0.75,'measured_at'=>now()];
        $record=PackagingMeasurementRecord::create($base+['status'=>'approved']);
        $profile=ProductPackagingProfile::create(['product_id'=>$p->id,'current_measurement_record_id'=>$record->id,'is_active'=>true]);
        $id=\Tests\Support\InventoryProductRateFixture::create($u->id);
        $draft=\Tests\Support\InventoryProductRateFixture::create($u->id,'draft');
        $url='/inventory/products/'.$p->id.'/rate-preview';
        $input=['measurement_record_id'=>$record->id,'version_id'=>$id,'warehouse_code'=>'FACTORY','carrier'=>'SYNTHETIC','service'=>'max-dim','country'=>'US','postal_code'=>'10001','as_of'=>'2026-11-27','eligibility_confirmed'=>true];
        $this->actingAs($this->userWith(['inventory.view','products.view']))->postJson($url,$input)->assertForbidden();
        $this->actingAs($u)->get('/inventory/products?view=logistics')->assertOk()->assertInertia(fn(Assert $page)=>$page->where('canPreviewRates',true)->has('rateScopes',1)->where('rateScopes.0.version_id',$id));
        $q=$this->postJson($url,$input+['weight_kg'=>99,'dimensions_cm'=>[99,99,99]])->assertOk()->assertJsonPath('status','ready')->assertJsonPath('measurement_record_id',$record->id)->json();
        $this->assertEqualsWithDelta(0.75,$q['components'][0]['actual_weight'],0.000001);
        $this->assertEqualsWithDelta(1.2,$q['volume_weight_kg'],0.000001);
        $this->assertEqualsWithDelta(1.5,$q['billable_weight_kg'],0.000001);
        $this->assertEqualsWithDelta(20,$q['total_cny'],0.000001);
        $this->assertSame('实重/体积重取大',$q['billing_basis']);
        $this->postJson($url,[...$input,'version_id'=>$draft])->assertOk()->assertJsonPath('reason','rate_version_not_approved')->assertJsonPath('total_cny',null);
        $this->postJson($url,[...$input,'eligibility_confirmed'=>false])->assertOk()->assertJsonPath('reason','eligibility_unconfirmed');
        $this->postJson($url,[...$input,'country'=>'DE'])->assertOk()->assertJsonPath('reason','rate_scope_missing');
        $this->postJson($url,[...$input,'measurement_record_id'=>$record->id+100])->assertOk()->assertJsonPath('reason','measurement_missing_or_changed');
        $record->update(['status'=>'submitted']);
        $this->postJson($url,$input)->assertOk()->assertJsonPath('reason','measurement_missing_or_changed')->assertJsonPath('total_cny',null);
        $this->assertDatabaseCount('orders',0);
        $this->assertDatabaseCount('logistics_rate_versions',2);
        $this->assertDatabaseHas('product_packaging_profiles',['id'=>$profile->id,'current_measurement_record_id'=>$record->id]);
        $this->assertSame('9.0000',$p->fresh()->shipping_weight_kg);
    }

    public function test_inventory_permissions_and_export_and_catalog_exclusion(): void
    {
        $p=Product::create(['sku'=>'SIZE-D','name'=>'D','is_active'=>true]);
        Product::create(['sku'=>'00W-SIZE','name'=>'Excluded','is_active'=>true]);
        ProductWeightEvidence::create(['product_id'=>$p->id,'weight_kind'=>'shipping_reference_weight','observed_weight_kg'=>0.8,'source_type'=>'logistics_scan','status'=>'adopted']);
        $inventory=$this->userWith(['inventory.view']);
        $this->actingAs($inventory)->get('/inventory/products')->assertOk()->assertInertia(fn(Assert $page)=>$page->where('canViewLogistics',false)->where('logisticsRows',null));
        $this->get('/inventory/products?view=logistics')->assertForbidden();
        $this->get('/inventory/products?view=sources')->assertForbidden();
        $this->get('/inventory/products/export-logistics')->assertForbidden();
        $products=$this->userWith(['products.view']);
        $this->actingAs($products)->get('/inventory/products?view=sources')->assertForbidden();
        $both=$this->userWith(['inventory.view','products.view']);
        $this->actingAs($both)->get('/inventory/products?view=logistics')->assertOk()->assertInertia(fn(Assert $page)=>$page->where('inventoryView','logistics')->where('canViewLogistics',true)->where('logisticsRows.0.sku','SIZE-D')->has('logisticsRows',1)->where('sourceRows',null));
        $this->get('/inventory/products?view=sources')->assertOk()->assertInertia(fn(Assert $page)=>$page->where('sourceRows.0.kind','运输参考重量')->where('sourceRows.0.current',false)->where('logisticsRows',null));
        $response=$this->get('/inventory/products/export-logistics?view=logistics')->assertOk()->assertHeader('Content-Type','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringStartsWith('PK',$response->streamedContent());
    }
}
