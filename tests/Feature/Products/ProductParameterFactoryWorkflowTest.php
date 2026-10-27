<?php

namespace Tests\Feature\Products;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductParameterAccessory;
use App\Modules\Products\Infrastructure\Models\ProductParameterSubmission;
use App\Modules\Products\Infrastructure\Models\ProductParameterTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ProductParameterFactoryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'factory-v2-'.uniqid(), 'label' => 'Factory V2 test']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user->roles()->attach($role);
        return $user;
    }

    private function product(): Product
    {
        return Product::create(['sku' => 'FACTORY-'.uniqid(), 'name' => 'Factory Product',
            'product_type' => 'simple', 'source_type' => 'manual', 'weight_kg' => 1.5,
            'shipping_weight_kg' => 1.75, 'is_active' => true]);
    }

    private function assign(Product $product, User $factory, bool $required = true): ProductParameterTask
    {
        return ProductParameterTask::create(['product_id' => $product->id,
            'assigned_user_id' => $factory->id, 'enabled' => true, 'is_required' => $required,
            'required_fields' => $required ? ['net_weight_kg', 'package_length_cm'] : []]);
    }

    private function measurement(): array
    {
        return ['measurements' => ['material' => 'cotton + memory foam',
            'weight_composition' => 'Main pillow 1589g, small pillow 100g',
            'net_weight_kg' => 1.589, 'package_length_cm' => 50,
            'package_width_cm' => 23, 'package_height_cm' => 19,
            'color_box_gross_kg' => 1.64, 'courier_gross_kg' => 1.8,
            'carton_length_cm' => 100, 'carton_width_cm' => 60, 'carton_height_cm' => 50,
            'units_per_carton' => 12, 'carton_gross_weight_kg' => 20],
            'accessories' => [['name' => 'small pillow', 'quantity' => 1, 'unit' => 'pcs']]];
    }

    public function test_product_viewer_can_fill_and_submit_without_factory_assignment_but_cannot_approve(): void
    {
        $p = $this->product();
        $viewer = $this->userWith(['products.view']);
        $root = '/products/factory-parameters/'.$p->id;
        $this->actingAs($viewer)->get($root)->assertOk()
            ->assertInertia(fn ($page) => $page->component('Products/FactoryParameters')
                ->where('task.is_required', false));
        $this->post($root.'/draft', $this->measurement())->assertRedirect();
        $s = ProductParameterSubmission::where('product_id', $p->id)->firstOrFail();
        $this->assertNull($s->task_id);
        $this->assertSame((int)$viewer->id,(int)$s->created_by);
        $this->post($root.'/submissions/'.$s->id.'/submit')->assertRedirect();
        $this->assertSame('submitted',$s->fresh()->status);
        $this->post('/products/parameter-submissions/'.$p->id.'/'.$s->id.'/review',
            ['decision'=>'approved'])->assertForbidden();
        $this->assertSame('1.5000',(string)$p->fresh()->weight_kg);
    }

    public function test_general_viewer_cannot_modify_another_user_draft_or_cross_product_submission(): void
    {
        $p = $this->product();
        $otherProduct = $this->product();
        $owner = $this->userWith(['products.view']);
        $other = $this->userWith(['products.view']);
        $root = '/products/factory-parameters/'.$p->id;
        $this->actingAs($owner)->post($root.'/draft', $this->measurement())->assertRedirect();
        $s = ProductParameterSubmission::where('product_id', $p->id)->firstOrFail();
        $this->actingAs($other)->post($root.'/submissions/'.$s->id.'/submit')->assertForbidden();
        $this->post('/products/factory-parameters/'.$otherProduct->id.'/submissions/'.$s->id.'/submit')->assertForbidden();
        $this->post('/products/factory-parameters/'.$otherProduct->id.'/submissions/'.$s->id.'/photo',
            ['photo'=>UploadedFile::fake()->image('notmine.jpg')])->assertForbidden();
        $this->assertSame('draft', $s->fresh()->status);
    }

    public function test_unassigned_and_unauthorized_factory_access_fail_closed(): void
    {
        $p = $this->product();
        $factory = $this->userWith(['products.parameters.submit']);
        $stranger = $this->userWith(['products.parameters.submit']);
        $viewer = $this->userWith(['products.view']);
        $this->assign($p, $factory);

        $url = '/products/factory-parameters/'.$p->id;
        $this->get($url)->assertRedirect('/login');
        $this->actingAs($stranger)->get($url)->assertForbidden();
        $this->actingAs($viewer)->get($url)->assertOk();
        $this->actingAs($factory)->get($url)->assertOk()
            ->assertInertia(fn ($page) => $page->component('Products/FactoryParameters')
                ->missing('costs')->has('product.sku')->has('task.required_fields'));
    }

    public function test_legacy_inventory_packaging_submit_does_not_grant_product_parameters_access(): void
    {
        $product=$this->product();
        $packingEmployee=$this->userWith(['inventory.packaging.submit']);
        $task=ProductParameterTask::create([
            'product_id'=>$product->id,
            'assigned_user_id'=>$packingEmployee->id,
            'enabled'=>true, 'is_required'=>false, 'required_fields'=>[],
        ]);
        $this->actingAs($packingEmployee)->get('/products/factory-parameters')->assertForbidden();
        $this->get('/products/factory-parameters/'.$product->id)->assertForbidden();
        $this->post('/products/factory-parameters/'.$product->id.'/draft',
            ['measurements'=>['material'=>'cotton']])->assertForbidden();
        $this->assertDatabaseCount('product_parameter_submissions',0);
        $this->assertSame((int) $packingEmployee->id, (int) $task->fresh()->assigned_user_id);
    }

    public function test_factory_can_save_and_submit_without_photo_without_changing_product_master(): void
    {
        $p = $this->product();
        $factory = $this->userWith(['products.parameters.submit']);
        $this->assign($p, $factory);
        $root = '/products/factory-parameters/'.$p->id;
        $this->actingAs($factory)->post($root.'/draft', $this->measurement())->assertRedirect();
        $s = ProductParameterSubmission::where('product_id', $p->id)->firstOrFail();
        $this->assertSame('draft', $s->status);
        $this->assertSame(0, $s->media()->count());
        $this->post($root.'/submissions/'.$s->id.'/submit')->assertRedirect();
        $this->assertSame('submitted', $s->fresh()->status);
        $this->assertSame('1.5000', (string) $p->fresh()->weight_kg);
        $this->assertSame('1.7500', (string) $p->fresh()->shipping_weight_kg);
        $this->post($root.'/submissions/'.$s->id.'/submit')->assertForbidden();
        $this->post($root.'/draft', ['measurements' => ['material' => 'corrected']])->assertRedirect();
        $this->assertDatabaseCount('product_parameter_submissions', 2);
    }

    public function test_required_field_blocks_submit_but_not_draft_and_photo_remains_optional(): void
    {
        $p = $this->product();$factory = $this->userWith(['products.parameters.submit']);
        $this->assign($p, $factory);
        $root = '/products/factory-parameters/'.$p->id;
        $this->actingAs($factory)->post($root.'/draft',
            ['measurements'=>['net_weight_kg'=>1.6]])->assertRedirect();
        $s = ProductParameterSubmission::firstOrFail();
        $this->post($root.'/submissions/'.$s->id.'/submit')->assertSessionHasErrors('measurements');
        $this->assertSame('draft', $s->fresh()->status);
    }

    public function test_admin_can_assign_and_review_accessories_only_on_explicit_approval(): void
    {
        $p=$this->product();
        $admin=$this->userWith(['products.view','products.operate']);
        $factory=$this->userWith(['products.parameters.submit']);
        $url='/products/parameter-tasks/'.$p->id;
        $this->actingAs($admin)->post($url,['enabled'=>true,'is_required'=>true,
            'assigned_user_id'=>$factory->id,'required_fields'=>['net_weight_kg']])->assertRedirect();
        $this->assertDatabaseHas('product_parameter_tasks',['product_id'=>$p->id,
            'assigned_user_id'=>$factory->id,'enabled'=>1]);
        $root='/products/factory-parameters/'.$p->id;
        $this->actingAs($factory)->post($root.'/draft',$this->measurement())->assertRedirect();
        $s=ProductParameterSubmission::firstOrFail();
        $this->post($root.'/submissions/'.$s->id.'/submit')->assertRedirect();
        $review='/products/parameter-submissions/'.$p->id.'/'.$s->id.'/review';
        $this->actingAs($factory)->post($review,['decision'=>'approved','apply_accessories'=>true])->assertForbidden();
        $this->assertDatabaseCount('product_parameter_accessories',0);
        $this->actingAs($admin)->post($review,['decision'=>'approved','apply_accessories'=>true])->assertRedirect();
        $this->assertSame('approved',$s->fresh()->status);
        $this->assertSame('small pillow', ProductParameterAccessory::firstOrFail()->name);
        $this->assertSame('1.5000',(string)$p->fresh()->weight_kg);
        $this->post($review,['decision'=>'rejected'])->assertStatus(422);
    }

    public function test_approval_cannot_wipe_existing_accessories_with_empty_submission(): void
    {
        $product=$this->product();
        $factory=$this->userWith(['products.parameters.submit']);
        $admin=$this->userWith(['products.operate']);
        $task=$this->assign($product,$factory,false);
        $older=ProductParameterSubmission::query()->create([
            'product_id'=>$product->id, 'task_id'=>$task->id, 'created_by'=>$factory->id,
            'status'=>'approved', 'measurements'=>['material'=>'cotton'],
            'accessories'=>[['name'=>'Existing pillow','quantity'=>1,'unit'=>'pcs']],
            'submitted_at'=>now(), 'reviewed_at'=>now(), 'reviewed_by'=>$admin->id,
        ]);
        ProductParameterAccessory::query()->create([
            'product_id'=>$product->id, 'approved_submission_id'=>$older->id,
            'name'=>'Existing pillow', 'quantity'=>1, 'unit'=>'pcs',
        ]);
        $new=ProductParameterSubmission::query()->create([
            'product_id'=>$product->id, 'task_id'=>$task->id, 'created_by'=>$factory->id,
            'status'=>'submitted', 'measurements'=>['net_weight_kg'=>1.62],
            'accessories'=>[], 'submitted_at'=>now(),
        ]);
        $url='/products/parameter-submissions/'.$product->id.'/'.$new->id.'/review';
        $this->actingAs($admin)->post($url,[
            'decision'=>'approved','apply_accessories'=>true,
        ])->assertSessionHasErrors('apply_accessories');
        $this->assertSame('submitted',$new->fresh()->status);
        $this->assertDatabaseCount('product_parameter_accessories',1);
        $this->assertSame('Existing pillow',ProductParameterAccessory::firstOrFail()->name);
        $this->post($url,['decision'=>'approved','apply_accessories'=>false])->assertRedirect();
        $this->assertSame('approved',$new->fresh()->status);
        $this->assertSame('Existing pillow',ProductParameterAccessory::firstOrFail()->name);
        $this->assertSame('1.5000',(string)$product->fresh()->weight_kg);
    }

    public function test_cross_product_draft_or_photo_access_is_rejected(): void
    {
        Storage::fake('local');
        $a=$this->product();$b=$this->product();
        $factory=$this->userWith(['products.parameters.submit']);
        $other=$this->userWith(['products.parameters.submit']);
        $this->assign($a,$factory);$this->assign($b,$other);
        $root='/products/factory-parameters/'.$a->id;
        $this->actingAs($factory)->post($root.'/draft',$this->measurement())->assertRedirect();
        $s=ProductParameterSubmission::firstOrFail();
        $this->actingAs($other)->post($root.'/submissions/'.$s->id.'/submit')->assertForbidden();
        $this->post($root.'/submissions/'.$s->id.'/photo',
            ['photo'=>UploadedFile::fake()->image('scale.jpg')])->assertForbidden();
        $this->actingAs($factory)->post('/products/factory-parameters/'.$b->id.'/submissions/'.$s->id.'/submit')->assertForbidden();
        $this->post($root.'/submissions/'.$s->id.'/photo',
            ['photo'=>UploadedFile::fake()->image('scale.jpg')])->assertRedirect();
        $this->assertDatabaseCount('product_parameter_media',1);
        $this->assertDatabaseCount('product_parameter_accessories',0);
    }

    public function test_factory_inbox_contains_only_assigned_products_and_supports_filtering(): void
    {
        $one=$this->product(); $two=$this->product();
        $factory=$this->userWith(['products.parameters.submit']);
        $other=$this->userWith(['products.parameters.submit']);
        $this->assign($one,$factory);
        $this->assign($two,$other);
        $this->actingAs($factory)->get('/products/factory-parameters')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Products/FactoryParameterTasks')
                ->has('tasks',1)->where('tasks.0.product_id',$one->id));
        $this->actingAs($other)->get('/products/factory-parameters')->assertOk()
            ->assertInertia(fn ($p) => $p->has('tasks',1)->where('tasks.0.product_id',$two->id));
    }

    public function test_unrealistic_carton_geometry_blocks_approval_but_does_not_delete_submitted_evidence(): void
    {
        $p=$this->product();
        $factory=$this->userWith(['products.parameters.submit']);
        $admin=$this->userWith(['products.operate']);
        $this->assign($p,$factory);
        $data=$this->measurement();
        $data['measurements']['carton_length_cm']=60;
        $data['measurements']['carton_width_cm']=40;
        $data['measurements']['carton_height_cm']=50;
        $root='/products/factory-parameters/'.$p->id;
        $this->actingAs($factory)->post($root.'/draft',$data)->assertRedirect();
        $submission=ProductParameterSubmission::firstOrFail();
        $this->post($root.'/submissions/'.$submission->id.'/submit')->assertRedirect();
        $this->actingAs($admin)->post('/products/parameter-submissions/'.$p->id.'/'.$submission->id.'/review',
            ['decision'=>'approved','apply_accessories'=>true])->assertSessionHasErrors('measurements');
        $this->assertSame('submitted',$submission->fresh()->status);
        $this->assertDatabaseCount('product_parameter_accessories',0);
        $this->assertSame('1.5000',(string)$p->fresh()->weight_kg);
    }

    public function test_inactive_or_unauthorized_employee_cannot_be_assigned(): void
    {
        $p=$this->product();
        $admin=$this->userWith(['products.operate']);
        $viewer=$this->userWith(['products.view']);
        $factory=$this->userWith(['products.parameters.submit']);
        $url='/products/parameter-tasks/'.$p->id;
        $this->actingAs($admin)->post($url,['enabled'=>true,'is_required'=>true,
            'assigned_user_id'=>$viewer->id,'required_fields'=>['net_weight_kg']])
            ->assertSessionHasErrors('assigned_user_id');
        $factory->update(['is_active'=>false]);
        $this->post($url,['enabled'=>true,'is_required'=>true,
            'assigned_user_id'=>$factory->id,'required_fields'=>['net_weight_kg']])
            ->assertSessionHasErrors('assigned_user_id');
        $this->post($url,['enabled'=>true,'is_required'=>true,
            'assigned_user_id'=>$viewer->id,'required_fields'=>[]])
            ->assertSessionHasErrors();
        $this->assertDatabaseCount('product_parameter_tasks',0);
    }

    public function test_rejection_requires_a_reason_and_does_not_erase_previous_approval(): void
    {
        $p=$this->product();
        $factory=$this->userWith(['products.parameters.submit']);
        $admin=$this->userWith(['products.operate']);
        $this->assign($p,$factory,false);
        $root='/products/factory-parameters/'.$p->id;
        $this->actingAs($factory)->post($root.'/draft', ['measurements'=>['material'=>'cotton']])->assertRedirect();
        $submission=ProductParameterSubmission::firstOrFail();
        $this->post($root.'/submissions/'.$submission->id.'/submit')->assertRedirect();
        $review='/products/parameter-submissions/'.$p->id.'/'.$submission->id.'/review';
        $this->actingAs($admin)->post($review,['decision'=>'rejected'])->assertSessionHasErrors('review_note');
        $this->post($review,['decision'=>'rejected','review_note'=>'请确认材质来源'])->assertRedirect();
        $this->assertSame('rejected',$submission->fresh()->status);
        $this->assertSame('请确认材质来源',$submission->fresh()->review_note);
    }

    public function test_invalid_fields_and_unassigned_products_are_never_modified(): void
    {
        $p=$this->product();$factory=$this->userWith(['products.parameters.submit']);
        $this->assign($p,$factory);
        $url='/products/factory-parameters/'.$p->id.'/draft';
        $this->actingAs($factory)->post($url,['measurements'=>['payment_amount'=>100]])->assertSessionHasErrors();
        $this->post($url,['measurements'=>['net_weight_kg'=>-8]])->assertSessionHasErrors();
        $this->assertDatabaseCount('product_parameter_submissions',0);
        $this->assertSame('1.5000',(string)$p->fresh()->weight_kg);
    }
}
