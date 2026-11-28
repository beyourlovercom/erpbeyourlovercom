<?php

namespace Tests\Feature\Inventory;

use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Infrastructure\Models\PackagingMeasurementRecord;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductPackagingProfile;
use App\Modules\Products\Infrastructure\Models\ProductPackagingProfileMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class PackagingMeasurementEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_can_create_and_submit_only_complete_evidence(): void
    {
        Storage::fake('local');
        $factory = $this->userWith(['inventory.packaging.submit']);
        $reviewer = $this->userWith(['inventory.packaging.review']);
        $product = Product::create(['name'=>'Factory Product','sku'=>'FACT-001']);
        $this->actingAs($factory)->get('/inventory')->assertForbidden();
        $this->actingAs($factory)->get('/products')->assertForbidden();
        $this->actingAs($factory)->post('/inventory/packaging', ['product_id'=>$product->id])->assertRedirect();
        $record = PackagingMeasurementRecord::firstOrFail();
        $this->actingAs($factory)->post("/inventory/packaging/{$record->id}/submit", [])->assertSessionHasErrors(['packaging_length_cm','packaging_width_cm','packaging_height_cm','packaged_weight_kg','measured_at']);
        $this->actingAs($factory)->post("/inventory/packaging/{$record->id}/media", ['kind'=>'measurement','files'=>[UploadedFile::fake()->image('measurement.jpg')]])->assertRedirect();
        $this->actingAs($factory)->post("/inventory/packaging/{$record->id}/media", ['kind'=>'weight','files'=>[UploadedFile::fake()->image('weight.png')]])->assertRedirect();
        $this->actingAs($factory)->post("/inventory/packaging/{$record->id}/submit", ['product_id'=>$product->id,'packaging_length_cm'=>'10','packaging_width_cm'=>'8','packaging_height_cm'=>'5','packaged_weight_kg'=>'0.250','measured_at'=>'2026-09-03 10:00:00'])->assertRedirect()->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('packaging_measurement_records', ['id'=>$record->id,'status'=>'submitted','product_id'=>$product->id,'packaging_method_key'=>null]);
        $this->assertNull(Product::find($product->id)->weight_kg);
        $this->assertSame(1, $reviewer->fresh()->unreadNotifications()->count());
        $this->assertSame('新的包装测量待审核', $reviewer->fresh()->unreadNotifications()->firstOrFail()->data['title']);
        $this->assertSame(0, $factory->fresh()->unreadNotifications()->count());
        Storage::disk('local')->assertExists($record->media()->where('kind','measurement')->firstOrFail()->path);
    }

    public function test_factory_login_lands_on_packaging_workspace_without_dashboard_permission(): void
    {
        $factory = $this->userWith(['inventory.packaging.submit']);
        $factory->forceFill(['email' => 'factory-login@example.test', 'password' => bcrypt('password')])->save();

        $this->post('/login', ['email' => 'factory-login@example.test', 'password' => 'password'])
            ->assertRedirect('/inventory/packaging');
        $this->assertAuthenticatedAs($factory);
    }

    public function test_invalid_media_is_rejected_and_submitter_cannot_read_another_record(): void
    {
        Storage::fake('local');
        $one = $this->userWith(['inventory.packaging.submit']); $two = $this->userWith(['inventory.packaging.submit']);
        $product = Product::create(['name'=>'Private Product','sku'=>'PRIVATE-001']);
        $record = PackagingMeasurementRecord::create(['product_id'=>$product->id,'status'=>'draft','created_by'=>$one->id]);
        $this->actingAs($one)->post("/inventory/packaging/{$record->id}/media", ['kind'=>'packaging','files'=>[UploadedFile::fake()->create('bad.svg', 2, 'image/svg+xml')]])->assertSessionHasErrors('files.0');
        $this->actingAs($two)->get("/inventory/packaging/{$record->id}")->assertForbidden();
    }

    public function test_reviewer_can_approve_but_history_cannot_be_mutated(): void
    {
        $submitter = $this->userWith(['inventory.packaging.submit']); $reviewer = $this->userWith(['inventory.packaging.review']);
        $product = Product::create(['name'=>'Review Product','sku'=>'REVIEW-001']);
        $record = PackagingMeasurementRecord::create(['product_id'=>$product->id,'status'=>'submitted','created_by'=>$submitter->id,'submitted_at'=>now(),'packaging_length_cm'=>1,'packaging_width_cm'=>2,'packaging_height_cm'=>3,'packaged_weight_kg'=>'0.100','measured_at'=>now()]);
        $this->actingAs($reviewer)->post("/inventory/packaging/{$record->id}/review", ['decision'=>'approved'])->assertRedirect();
        $this->assertDatabaseHas('packaging_measurement_records', ['id'=>$record->id,'status'=>'approved','reviewed_by'=>$reviewer->id]);
        $this->assertSame(1, $submitter->fresh()->unreadNotifications()->count());
        $this->assertSame('包装测量已批准', $submitter->fresh()->unreadNotifications()->firstOrFail()->data['title']);
        $this->actingAs($submitter)->patch("/inventory/packaging/{$record->id}", ['product_id'=>$product->id])->assertForbidden();
    }

    public function test_record_detail_exposes_only_its_products_active_packaging_sop(): void
    {
        $factory = $this->userWith(['inventory.packaging.submit']);
        $product = Product::create(['name'=>'Compressed Pillow','sku'=>'SOP-001']);
        $other = Product::create(['name'=>'Other Product','sku'=>'SOP-002']);
        ProductPackagingProfile::create([
            'product_id'=>$product->id,
            'preset_key'=>null,
            'custom_method'=>'气泡袋包裹',
            'custom_materials'=>['气泡袋','胶带','标签'],
            'custom_steps'=>[['position'=>1,'title'=>'准备材料','instruction'=>'准备材料']],
            'custom_tips'=>['接口处加固'],
            'is_active'=>true,
        ]);
        $record = PackagingMeasurementRecord::create(['product_id'=>$product->id,'status'=>'draft','packaging_method_key'=>'custom','created_by'=>$factory->id]);
        $otherRecord = PackagingMeasurementRecord::create(['product_id'=>$other->id,'status'=>'draft','created_by'=>$factory->id]);

        $this->actingAs($factory)->get("/inventory/packaging/{$record->id}")->assertInertia(fn (Assert $page) => $page
            ->where('packagingSop.method', '气泡袋包裹')
            ->has('packagingSop.materials', 3)
            ->has('packagingSop.steps', 1));
        $this->actingAs($factory)->get("/inventory/packaging/{$otherRecord->id}")->assertInertia(fn (Assert $page) => $page
            ->where('packagingSop', null));
    }

    public function test_factory_guide_exposes_presets_and_record_detail_uses_selected_method(): void
    {
        $factory = $this->userWith(['inventory.packaging.submit']);
        $product = Product::create(['name'=>'Guide Product','sku'=>'GUIDE-001']);
        ProductPackagingProfile::create([
            'product_id'=>$product->id,
            'preset_key'=>'bubble_wrap_cut',
            'is_active'=>true,
        ]);

        $this->actingAs($factory)->get("/inventory/packaging/product/{$product->id}/guide")
            ->assertOk()
            ->assertJsonPath('packaging_sop.preset_key', 'bubble_wrap_cut')
            ->assertJsonCount(4, 'presets');

        $record = PackagingMeasurementRecord::create([
            'product_id'=>$product->id,
            'status'=>'draft',
            'packaging_method_key'=>'carton',
            'created_by'=>$factory->id,
        ]);

        $this->actingAs($factory)->get("/inventory/packaging/{$record->id}")->assertInertia(fn (Assert $page) => $page
            ->where('record.packaging_method_key', 'carton')
            ->where('packagingSop.method', '加纸箱')
            ->where('packagingSop.rendered_illustration_url', null));
    }

    public function test_packaging_reference_never_blocks_measurement_submission(): void
    {
        Storage::fake('local');
        $factory = $this->userWith(['inventory.packaging.submit']);
        $product = Product::create(['name'=>'Reference Product','sku'=>'REF-001']);
        $record = PackagingMeasurementRecord::create(['product_id'=>$product->id,'status'=>'draft','created_by'=>$factory->id]);
        $this->actingAs($factory)->post("/inventory/packaging/{$record->id}/media", ['kind'=>'measurement','files'=>[UploadedFile::fake()->image('measurement.jpg')]])->assertRedirect();
        $this->actingAs($factory)->post("/inventory/packaging/{$record->id}/media", ['kind'=>'weight','files'=>[UploadedFile::fake()->image('weight.jpg')]])->assertRedirect();
        $payload = ['product_id'=>$product->id,'packaging_method_key'=>'custom','packaging_length_cm'=>'10','packaging_width_cm'=>'8','packaging_height_cm'=>'5','packaged_weight_kg'=>'0.250','measured_at'=>'2026-09-04 09:00:00'];

        $this->actingAs($factory)->post("/inventory/packaging/{$record->id}/submit", $payload)
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('packaging_measurement_records', ['id'=>$record->id,'status'=>'submitted','packaging_method_key'=>'custom']);
    }

    public function test_product_operator_can_upload_private_packaging_reference_images_for_factory(): void
    {
        Storage::fake('local');
        $operator = $this->userWith(['products.operate']);
        $factory = $this->userWith(['inventory.packaging.submit']);
        $product = Product::create(['name'=>'Packaging Admin Product','sku'=>'PKG-ADMIN-001']);

        $this->actingAs($operator)->get("/products/{$product->id}/packaging")->assertOk();
        $this->actingAs($factory)->get("/products/{$product->id}/packaging")->assertForbidden();

        $this->actingAs($operator)->put("/products/{$product->id}/packaging", [
            'preset_key'=>'bubble_wrap_cut',
            'method'=>'气泡袋裁剪',
            'scenario'=>'工厂参考',
            'version'=>'v1',
            'units_per_carton'=>12,
        ])->assertRedirect();

        $this->actingAs($operator)->post("/products/{$product->id}/packaging/media", [
            'kind'=>'rendered',
            'files'=>[UploadedFile::fake()->image('hero.jpg')],
        ])->assertRedirect();
        $this->actingAs($operator)->post("/products/{$product->id}/packaging/media", [
            'kind'=>'example',
            'files'=>[UploadedFile::fake()->image('case-1.png'), UploadedFile::fake()->image('case-2.png')],
        ])->assertRedirect();

        $profile = ProductPackagingProfile::query()->where('product_id', $product->id)->firstOrFail();
        $this->assertDatabaseHas('product_packaging_profiles', ['id'=>$profile->id,'preset_key'=>'bubble_wrap_cut','units_per_carton'=>12,'custom_steps'=>null,'custom_tips'=>null]);
        $this->assertDatabaseCount('product_packaging_profile_media', 3);
        $hero = ProductPackagingProfileMedia::query()->where('product_packaging_profile_id',$profile->id)->where('kind','rendered')->firstOrFail();
        Storage::disk('local')->assertExists($hero->path);

        $this->actingAs($factory)->get("/products/packaging/media/{$hero->id}")->assertOk();
        $this->actingAs($factory)->get("/inventory/packaging/product/{$product->id}/guide")
            ->assertOk()
            ->assertJsonPath('packaging_sop.preset_key', 'bubble_wrap_cut')
            ->assertJsonCount(2, 'packaging_sop.examples');
    }

    public function test_global_presets_are_shared_and_product_rows_store_only_overrides(): void
    {
        $factory = $this->userWith(['inventory.packaging.submit']);
        $product = Product::create(['name'=>'Shared Template Product','sku'=>'SHARED-001']);
        ProductPackagingProfile::create(['product_id'=>$product->id,'preset_key'=>'bubble_wrap_cut','is_active'=>true]);

        $this->assertDatabaseCount('product_packaging_profiles', 1);
        $this->assertDatabaseHas('product_packaging_profiles', ['product_id'=>$product->id,'preset_key'=>'bubble_wrap_cut','custom_materials'=>null,'custom_steps'=>null,'custom_tips'=>null]);
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('packaging_presets'));

        config()->set('packaging_presets.bubble_wrap_cut.label', '气泡袋裁剪 · 全局更新');
        $this->actingAs($factory)->get("/inventory/packaging/product/{$product->id}/guide")
            ->assertOk()
            ->assertJsonCount(4, 'presets')
            ->assertJsonPath('packaging_sop.method', '气泡袋裁剪 · 全局更新')
            ->assertJsonCount(6, 'packaging_sop.steps');
    }


    public function test_reviewer_can_adopt_approved_measurement_as_current_product_packaging_standard(): void
    {
        $reviewer = $this->userWith(['inventory.packaging.review']);
        $product = Product::create(['name'=>'Standard Product','sku'=>'STANDARD-001']);
        $profile = ProductPackagingProfile::create(['product_id'=>$product->id,'preset_key'=>'bubble_wrap_cut','is_active'=>true]);
        $record = PackagingMeasurementRecord::create([
            'product_id'=>$product->id,'status'=>'approved','packaging_method_key'=>'bubble_wrap_cut',
            'packaging_length_cm'=>'32.00','packaging_width_cm'=>'18.00','packaging_height_cm'=>'12.00',
            'packaged_weight_kg'=>'0.850','measured_at'=>'2026-09-04 10:00:00','created_by'=>$reviewer->id,
            'reviewed_by'=>$reviewer->id,'reviewed_at'=>now(),
        ]);

        $this->actingAs($reviewer)->post("/inventory/packaging/{$record->id}/adopt-standard")->assertRedirect();
        $this->assertDatabaseHas('product_packaging_profiles', [
            'id'=>$profile->id,'product_id'=>$product->id,'current_measurement_record_id'=>$record->id,
        ]);
        $this->actingAs($reviewer)->get("/inventory/packaging/{$record->id}")->assertInertia(
            fn (Assert $page) => $page
                ->where('isCurrentStandard', true)
                ->where('currentStandardRecordId', $record->id)
        );
    }

    public function test_rejected_evidence_is_immutable_and_resubmission_requires_new_photos_and_preserves_standard_history(): void
    {
        Storage::fake('local');
        $factory = $this->userWith(['inventory.packaging.submit']);
        $other = $this->userWith(['inventory.packaging.submit']);
        $reviewer = $this->userWith(['inventory.packaging.review']);
        $product = Product::create(['name'=>'Synthetic','sku'=>'RESUBMIT-001']);
        $different = Product::create(['name'=>'Different','sku'=>'RESUBMIT-002']);
        $old = PackagingMeasurementRecord::create(['product_id'=>$product->id,'status'=>'approved','packaging_method_key'=>'carton','created_by'=>$reviewer->id,
            'packaged_weight_kg'=>2.232,'packaging_length_cm'=>39,'packaging_width_cm'=>25.5,'packaging_height_cm'=>18.7,'measured_at'=>now()]);
        $profile = ProductPackagingProfile::create(['product_id'=>$product->id,'preset_key'=>'carton','current_measurement_record_id'=>$old->id,'is_active'=>true]);
        $record = PackagingMeasurementRecord::create(['product_id'=>$product->id,'status'=>'draft','packaging_method_key'=>'document_bubble_mailer','created_by'=>$factory->id]);
        $upload = function ($r) use ($factory) {
            foreach (['measurement','weight'] as $kind) {
                $this->actingAs($factory)->post("/inventory/packaging/{$r->id}/media", ['kind'=>$kind,'files'=>[UploadedFile::fake()->image($kind.'.jpg')]])->assertRedirect();
            }
        };
        $data = ['product_id'=>$product->id,'packaging_method_key'=>'document_bubble_mailer','packaging_length_cm'=>39,'packaging_width_cm'=>25.5,'packaging_height_cm'=>13.4,'packaged_weight_kg'=>2.232,'measured_at'=>'2026-11-27 10:00:00'];
        $upload($record);
        $this->actingAs($factory)->patch("/inventory/packaging/{$record->id}", [...$data,'product_id'=>$different->id])->assertStatus(422);
        $this->actingAs($factory)->post("/inventory/packaging/{$record->id}/submit",$data)->assertRedirect();
        $this->actingAs($reviewer)->post("/inventory/packaging/{$record->id}/review",['decision'=>'rejected'])->assertSessionHasErrors('review_note');
        $this->actingAs($reviewer)->post("/inventory/packaging/{$record->id}/review",['decision'=>'rejected','review_note'=>'Synthetic protection evidence missing','require_photos'=>true])->assertRedirect();
        $this->actingAs($other)->post("/inventory/packaging/{$record->id}/resubmit")->assertForbidden();
        $this->actingAs($factory)->post("/inventory/packaging/{$record->id}/resubmit")->assertRedirect();
        $new = PackagingMeasurementRecord::query()->where('resubmission_of_id',$record->id)->firstOrFail();
        $this->actingAs($factory)->post("/inventory/packaging/{$record->id}/resubmit")->assertRedirect();
        $this->assertSame(1,PackagingMeasurementRecord::where('resubmission_of_id',$record->id)->count());
        $this->assertSame('rejected',$record->fresh()->status);
        $this->assertSame(2,$record->media()->count());
        $this->assertSame(0,$new->media()->count());
        $this->actingAs($factory)->postJson("/inventory/packaging/{$new->id}/submit",$data)->assertUnprocessable()->assertJsonValidationErrors("photos");
        $upload($new);
        $this->actingAs($factory)->post("/inventory/packaging/{$new->id}/submit",$data)->assertRedirect();
        $this->actingAs($factory)->post("/inventory/packaging/{$new->id}/review",['decision'=>'approved'])->assertForbidden();
        $this->actingAs($reviewer)->post("/inventory/packaging/{$new->id}/review",['decision'=>'approved'])->assertRedirect();
        $this->assertSame($old->id,$profile->fresh()->current_measurement_record_id);
        $this->actingAs($factory)->post("/inventory/packaging/{$new->id}/adopt-standard")->assertForbidden();
        $this->actingAs($reviewer)->post("/inventory/packaging/{$new->id}/adopt-standard",['expected_current_standard_record_id'=>null])->assertStatus(409);
        $this->actingAs($reviewer)->post("/inventory/packaging/{$new->id}/adopt-standard",['expected_current_standard_record_id'=>$old->id])->assertRedirect();
        $this->actingAs($reviewer)->post("/inventory/packaging/{$old->id}/adopt-standard",['expected_current_standard_record_id'=>$old->id])->assertStatus(409);
        $r=app(\App\Modules\Inventory\Application\ProductPackagingStandardReader::class)->forProductIds([$product->id])[$product->id];
        $this->assertSame($new->id,$r['measurement_record_id']);
        $this->assertSame('document_bubble_mailer',$r['packaging_method_key']);
        $this->assertSame('13.40',$r['packaging_height_cm']);
        $this->assertSame('approved',$old->fresh()->status);
        $this->assertSame('rejected',$record->fresh()->status);
        $this->assertSame(2,$record->media()->count());
    }

    public function test_normal_measurement_and_ordinary_rejection_do_not_require_photos(): void
    {
        $factory=$this->userWith(['inventory.packaging.submit']);
        $reviewer=$this->userWith(['inventory.packaging.review']);
        $p=Product::create(['name'=>'Optional evidence','sku'=>'OPTIONAL-PHOTO']);
        $r=PackagingMeasurementRecord::create(['product_id'=>$p->id,'status'=>'draft','created_by'=>$factory->id]);
        $data=['product_id'=>$p->id,'packaging_method_key'=>'carton','packaging_length_cm'=>30,'packaging_width_cm'=>20,'packaging_height_cm'=>10,'packaged_weight_kg'=>1,'measured_at'=>'2026-11-27 10:00:00'];
        $this->actingAs($factory)->post("/inventory/packaging/{$r->id}/submit",[...$data,'photo_requirement_reason'=>'reviewer_requested','require_photos'=>true])->assertRedirect()->assertSessionDoesntHaveErrors();
        $this->assertNull($r->fresh()->photo_requirement_reason);
        $this->assertSame(0,$r->media()->count());
        $this->actingAs($reviewer)->post("/inventory/packaging/{$r->id}/review",['decision'=>'rejected','review_note'=>'Correct note'])->assertRedirect();
        $this->actingAs($factory)->post("/inventory/packaging/{$r->id}/resubmit")->assertRedirect();
        $new=PackagingMeasurementRecord::where('resubmission_of_id',$r->id)->firstOrFail();
        $this->assertNull($new->photo_requirement_reason);
        $this->actingAs($factory)->post("/inventory/packaging/{$new->id}/submit",$data)->assertRedirect()->assertSessionDoesntHaveErrors();
        $this->actingAs($reviewer)->post("/inventory/packaging/{$new->id}/review",['decision'=>'approved'])->assertRedirect();
        $this->actingAs($reviewer)->post("/inventory/packaging/{$new->id}/adopt-standard",['expected_current_standard_record_id'=>null])->assertRedirect();
        $this->assertDatabaseHas('product_packaging_profiles',['product_id'=>$p->id,'current_measurement_record_id'=>$new->id]);
        $this->assertSame(0,$new->media()->count());
    }

    public function test_clear_same_method_weight_or_volume_deviation_requires_photos_but_changed_method_is_optional(): void
    {
        Storage::fake('local');
        $factory=$this->userWith(['inventory.packaging.submit']);
        $reviewer=$this->userWith(['inventory.packaging.review']);
        $p=Product::create(['name'=>'Deviation evidence','sku'=>'DEVIATION-PHOTO']);
        $data=['product_id'=>$p->id,'packaging_method_key'=>'carton','packaging_length_cm'=>30,'packaging_width_cm'=>20,'packaging_height_cm'=>10,'packaged_weight_kg'=>1,'measured_at'=>'2026-11-27 10:00:00'];
        $old=PackagingMeasurementRecord::create([...$data,'status'=>'approved','created_by'=>$reviewer->id]);
        ProductPackagingProfile::create(['product_id'=>$p->id,'current_measurement_record_id'=>$old->id,'preset_key'=>'carton','is_active'=>true]);
        $r=PackagingMeasurementRecord::create(['product_id'=>$p->id,'status'=>'draft','created_by'=>$factory->id]);
        $this->actingAs($factory)->postJson("/inventory/packaging/{$r->id}/submit",[...$data,'packaged_weight_kg'=>2])->assertUnprocessable()->assertJsonValidationErrors('photos');
        $this->actingAs($factory)->postJson("/inventory/packaging/{$r->id}/submit",[...$data,'packaging_height_cm'=>5])->assertUnprocessable()->assertJsonValidationErrors('photos');
        $this->assertSame('draft',$r->fresh()->status);
        $this->actingAs($factory)->post("/inventory/packaging/{$r->id}/media",['kind'=>'weight','files'=>[UploadedFile::fake()->image('weight.jpg')]])->assertRedirect();
        $this->actingAs($factory)->postJson("/inventory/packaging/{$r->id}/submit",[...$data,'packaged_weight_kg'=>2])->assertUnprocessable()->assertJsonValidationErrors('photos');
        $this->actingAs($factory)->post("/inventory/packaging/{$r->id}/media",['kind'=>'measurement','files'=>[UploadedFile::fake()->image('measurement.jpg')]])->assertRedirect();
        $this->actingAs($factory)->post("/inventory/packaging/{$r->id}/submit",[...$data,'packaged_weight_kg'=>2])->assertRedirect()->assertSessionDoesntHaveErrors();
        $this->assertSame('material_deviation',$r->fresh()->photo_requirement_reason);
        $other=PackagingMeasurementRecord::create(['product_id'=>$p->id,'status'=>'draft','created_by'=>$factory->id]);
        $this->actingAs($factory)->post("/inventory/packaging/{$other->id}/submit",[...$data,'packaging_method_key'=>'document_bubble_mailer','packaging_height_cm'=>4])->assertRedirect()->assertSessionDoesntHaveErrors();
        $this->assertNull($other->fresh()->photo_requirement_reason);
        $this->assertSame(0,$other->media()->count());
    }

    public function test_optimization_workspace_enforces_role_and_canonical_catalogue_scope(): void
    {
        $factory=$this->userWith(['inventory.packaging.submit']);
        $reviewer=$this->userWith(['inventory.packaging.review']);
        $viewer=$this->userWith(['inventory.view']);
        $p=Product::create(['name'=>'Synthetic workspace','sku'=>'B6-CANONICAL','is_active'=>true]);
        $excluded=Product::create(['name'=>'Excluded','sku'=>'00W-B6','is_active'=>true]);
        $r=PackagingMeasurementRecord::create(['product_id'=>$p->id,'status'=>'approved','packaging_method_key'=>'carton','created_by'=>$factory->id,'packaged_weight_kg'=>2.232,'packaging_length_cm'=>39,'packaging_width_cm'=>25.5,'packaging_height_cm'=>18.7,'measured_at'=>now()]);
        ProductPackagingProfile::create(['product_id'=>$p->id,'preset_key'=>'carton','current_measurement_record_id'=>$r->id,'is_active'=>true]);
        $url='/inventory/packaging?tab=optimization&search=B6-CANONICAL&product_id='.$p->id;
        $this->actingAs($factory)->get($url)->assertForbidden();
        $this->actingAs($viewer)->get($url)->assertOk()->assertInertia(fn($page)=>$page->component('Inventory/Packaging/Index')->where('canQuote',false)->where('optimization.stats.total',1)->where('selected.geometry.baseline.candidate_height_cm',13.4)->has('selected.routes',0)->where('selected.history.monthly_saving_cny',null)->where('selected.history.eligible_for_ranking',false)->missing('selected.product.input.evidence'));
        $this->actingAs($reviewer)->get($url)->assertOk()->assertInertia(fn($page)=>$page->where('canQuote',true)->where('selected.quote',null)->has('selected.methods',4));
        $this->actingAs($reviewer)->get('/inventory/packaging?tab=optimization&product_id='.$excluded->id)->assertNotFound();
        $this->actingAs($reviewer)->get('/inventory/packaging?tab=standards&search=B6-CANONICAL')->assertOk()->assertInertia(fn($page)=>$page->has('optimization.rows.data',1)->where('optimization.rows.data.0.sku','B6-CANONICAL'));
        $this->assertDatabaseCount('packaging_measurement_records',1);
        $this->assertDatabaseCount('product_packaging_profiles',1);
        $this->assertDatabaseCount('orders',0);
    }

    public function test_factory_confirmed_upgrade_keeps_baseline_and_actual_separate_until_adoption(): void
    {
        $factory=$this->userWith(['inventory.packaging.submit']);
        $reviewer=$this->userWith(['inventory.packaging.review']);
        $p=Product::create(['name'=>'Upgrade synthetic','sku'=>'UPGRADE-B6','is_active'=>true]);
        $other=Product::create(['name'=>'Other','sku'=>'UPGRADE-OTHER','is_active'=>true]);
        $base=PackagingMeasurementRecord::create(['product_id'=>$p->id,'status'=>'approved','packaging_method_key'=>'carton','created_by'=>$reviewer->id,'packaged_weight_kg'=>2.232,'packaging_length_cm'=>39,'packaging_width_cm'=>25.5,'packaging_height_cm'=>18.7,'measured_at'=>now()]);
        $profile=ProductPackagingProfile::create(['product_id'=>$p->id,'preset_key'=>'carton','current_measurement_record_id'=>$base->id,'is_active'=>true]);
        $path="/inventory/packaging/product/{$p->id}/upgrade";
        $request=['baseline_id'=>$base->id,'method'=>'document_bubble_mailer','divisor'=>6000];
        $this->actingAs($reviewer)->post($path,$request)->assertForbidden();
        $this->actingAs($factory)->post("/inventory/packaging/product/{$other->id}/upgrade",$request)->assertStatus(409);
        $this->actingAs($factory)->post($path,$request)->assertRedirect();
        $r=PackagingMeasurementRecord::where('upgrade_from_record_id',$base->id)->firstOrFail();
        $this->assertNull($r->packaged_weight_kg);$this->assertNull($r->packaging_height_cm);
        $this->assertSame(13.4,$r->upgrade_plan['proposed']['height_cm']);
        $this->assertSame(18.7,$r->upgrade_plan['baseline']['height_cm']);
        $this->actingAs($factory)->post($path,$request)->assertRedirect();
        $this->assertSame(1,PackagingMeasurementRecord::where('upgrade_from_record_id',$base->id)->count());
        $payload=['product_id'=>$p->id,'packaging_method_key'=>'document_bubble_mailer','packaging_length_cm'=>39,'packaging_width_cm'=>25.5,'packaging_height_cm'=>13.2,'packaged_weight_kg'=>2.18,'measured_at'=>'2026-11-28 14:00:00'];
        $this->actingAs($factory)->post("/inventory/packaging/{$r->id}/submit",[...$payload,'packaging_method_key'=>'carton'])->assertStatus(422);
        $this->actingAs($factory)->post("/inventory/packaging/{$r->id}/submit",$payload)->assertRedirect()->assertSessionDoesntHaveErrors();
        $this->assertSame($base->id,$profile->fresh()->current_measurement_record_id);
        $this->assertSame(0,$r->media()->count());
        $this->actingAs($factory)->get("/inventory/packaging/{$r->id}/upgrade-quote?country=US")->assertForbidden();
        $this->actingAs($reviewer)->get("/inventory/packaging/{$r->id}")->assertOk()->assertInertia(fn($page)=>$page->where('upgradeComparison.actual.height_cm',13.2)->where('upgradeComparison.weight_saving_kg',0.052)->where('upgradeComparison.proposed.height_cm',13.4));
        $this->actingAs($reviewer)->post("/inventory/packaging/{$r->id}/review",['decision'=>'approved'])->assertRedirect();
        $this->assertSame($base->id,$profile->fresh()->current_measurement_record_id);
        $this->actingAs($reviewer)->post("/inventory/packaging/{$r->id}/adopt-standard",['expected_current_standard_record_id'=>$base->id])->assertRedirect();
        $this->assertSame($r->id,$profile->fresh()->current_measurement_record_id);
        $this->assertNotNull($r->fresh()->standard_adopted_at);
        $this->assertSame('18.70',$base->fresh()->packaging_height_cm);
        $read=app(\App\Modules\Inventory\Application\ProductPackagingStandardReader::class)->forProductIds([$p->id])[$p->id];
        $this->assertSame('13.20',$read['packaging_height_cm']);$this->assertSame('2.180',$read['packaged_weight_kg']);
        $this->actingAs($factory)->post($path,$request)->assertStatus(409);
        $this->assertDatabaseCount('orders',0);
    }

    private function userWith(array $keys): User
    {
        $user = User::factory()->create(); $role = Role::create(['key'=>'test-'.uniqid(),'label'=>'Test']);
        $role->permissions()->sync(collect($keys)->map(fn($key)=>Permission::firstOrCreate(['key'=>$key],['label'=>$key]))->pluck('id'));
        $user->roles()->attach($role); return $user;
    }
}
