<?php

namespace Tests\Feature\Products;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductParameterAccessory;
use App\Modules\Products\Infrastructure\Models\ProductParameterSubmission;
use App\Modules\Products\Infrastructure\Models\ProductParameterTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductParameterBatchAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'p360-bulk-'.uniqid(), 'label' => 'Bulk UAT']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user->roles()->attach($role);
        return $user;
    }

    private function product(string $sku): Product
    {
        return Product::query()->create([
            'sku' => $sku, 'name' => $sku,
            'product_type' => 'simple', 'source_type' => 'manual',
            'is_active' => true, 'weight_kg' => 3.33,
            'shipping_weight_kg' => 4.43,
        ]);
    }

    private function payload(array $ids, int $assignedId): array
    {
        return [
            'product_ids' => $ids,
            'action' => 'required',
            'assigned_user_id' => $assignedId,
            'required_fields' => ['material', 'net_weight_kg'],
        ];
    }

    public function test_bulk_assigns_only_selected_product_ids_and_preserves_canonical_weights(): void
    {
        $admin=$this->userWith(['products.view','products.operate']);
        $factory=$this->userWith(['products.parameters.submit']);
        $p1=$this->product('BULK-001');
        $p2=$this->product('BULK-002');
        $untouched=$this->product('BULK-003');

        $this->actingAs($admin)->post('/products/parameter-tasks/bulk',
            $this->payload([$p1->id,$p2->id],$factory->id))->assertRedirect();
        $this->assertDatabaseCount('product_parameter_tasks',2);
        foreach ([$p1,$p2] as $p) {
            $t=ProductParameterTask::where('product_id',$p->id)->firstOrFail();
            $this->assertTrue($t->enabled);
            $this->assertTrue($t->is_required);
            $this->assertSame((int)$factory->id,(int)$t->assigned_user_id);
            $this->assertSame(['material','net_weight_kg'],$t->required_fields);
            $this->assertEquals(3.33,(float)$p->fresh()->weight_kg);
            $this->assertEquals(4.43,(float)$p->fresh()->shipping_weight_kg);
        }
        $this->assertDatabaseMissing('product_parameter_tasks',['product_id'=>$untouched->id]);
        $this->actingAs($factory)->get('/products/factory-parameters')->assertOk()
            ->assertInertia(fn ($page) => $page->has('tasks',2));
        $this->get('/products/list')->assertForbidden();
    }

    public function test_batch_optional_and_disable_preserves_existing_evidence_and_approved_parts(): void
    {
        $admin=$this->userWith(['products.operate']);
        $factory=$this->userWith(['products.parameters.submit']);
        $p=$this->product('BULK-004');$other=$this->product('BULK-005');
        $task=ProductParameterTask::query()->create([
            'product_id'=>$p->id,'assigned_user_id'=>$factory->id,
            'enabled'=>true,'is_required'=>true,'required_fields'=>['net_weight_kg'],
        ]);
        $submission=ProductParameterSubmission::query()->create([
            'product_id'=>$p->id,'task_id'=>$task->id,'created_by'=>$factory->id,
            'status'=>'approved','measurements'=>['material'=>'Cotton'],'accessories'=>[
                ['name'=>'Inner pillow','quantity'=>1,'unit'=>'pcs'],
            ],
        ]);
        ProductParameterAccessory::query()->create([
            'product_id'=>$p->id,'approved_submission_id'=>$submission->id,
            'name'=>'Inner pillow','quantity'=>1,'unit'=>'pcs',
        ]);
        $url='/products/parameter-tasks/bulk';
        $this->actingAs($admin)->post($url,[
            'product_ids'=>[$p->id,$other->id],
            'action'=>'optional','assigned_user_id'=>$factory->id,
        ])->assertRedirect();
        $this->assertFalse($task->fresh()->is_required);
        $this->assertTrue($task->fresh()->enabled);
        $this->post($url,['product_ids'=>[$p->id,$other->id],'action'=>'disable'])->assertRedirect();
        $this->assertFalse($task->fresh()->enabled);
        $this->assertDatabaseCount('product_parameter_tasks',2);
        $this->assertDatabaseCount('product_parameter_submissions',1);
        $this->assertSame('Inner pillow',ProductParameterAccessory::firstOrFail()->name);
        $this->assertEquals(3.33,(float)$p->fresh()->weight_kg);
        $never=$this->product('BULK-UNASSIGNED');
        $this->post($url,['product_ids'=>[$never->id],'action'=>'disable'])->assertRedirect();
        $this->assertDatabaseCount('product_parameter_tasks',2);
    }

    public function test_bulk_denies_factory_and_read_only_users_and_blocks_cross_scope(): void
    {
        $p=$this->product('BULK-006');
        $factory=$this->userWith(['products.parameters.submit']);
        $reader=$this->userWith(['products.view']);
        $url='/products/parameter-tasks/bulk';
        $this->actingAs($factory)->post($url,$this->payload([$p->id],$factory->id))->assertForbidden();
        $this->actingAs($reader)->post($url,$this->payload([$p->id],$factory->id))->assertForbidden();
        $this->assertDatabaseCount('product_parameter_tasks',0);
    }

    public function test_reassignment_requires_explicit_opt_in_and_is_fully_atomic(): void
    {
        $admin=$this->userWith(['products.operate']);
        $first=$this->userWith(['products.parameters.submit']);
        $second=$this->userWith(['products.parameters.submit']);
        $existing=$this->product('BULK-007');
        $new=$this->product('BULK-008');
        $task=ProductParameterTask::query()->create([
            'product_id'=>$existing->id,'assigned_user_id'=>$first->id,
            'enabled'=>true,'is_required'=>true,'required_fields'=>['material'],
        ]);
        $url='/products/parameter-tasks/bulk';
        $this->actingAs($admin)->post($url,$this->payload([$new->id,$existing->id],$second->id))
            ->assertSessionHasErrors('allow_reassign');
        $this->assertDatabaseCount('product_parameter_tasks',1);
        $this->assertSame((int)$first->id,(int)$task->fresh()->assigned_user_id);

        $payload=$this->payload([$existing->id,$new->id],$second->id);
        $payload['allow_reassign']=true;
        $this->post($url,$payload)->assertRedirect();
        $this->assertDatabaseCount('product_parameter_tasks',2);
        $this->assertSame((int)$second->id,(int)$task->fresh()->assigned_user_id);
    }

    public function test_pending_submissions_block_disabling_or_reassignment_atomically(): void
    {
        $admin=$this->userWith(['products.operate']);
        $first=$this->userWith(['products.parameters.submit']);
        $second=$this->userWith(['products.parameters.submit']);
        $blocked=$this->product('BULK-009');
        $other=$this->product('BULK-010');
        $task=ProductParameterTask::query()->create([
            'product_id'=>$blocked->id,'assigned_user_id'=>$first->id,
            'enabled'=>true,'is_required'=>true,'required_fields'=>['material'],
        ]);
        ProductParameterSubmission::query()->create([
            'task_id'=>$task->id,'product_id'=>$blocked->id,
            'created_by'=>$first->id,'status'=>'submitted','measurements'=>['material'=>'cotton'],
            'submitted_at'=>now(),
        ]);
        $url='/products/parameter-tasks/bulk';
        $this->actingAs($admin)->post($url,[
            'action'=>'disable','product_ids'=>[$other->id,$blocked->id],
        ])->assertSessionHasErrors('product_ids');
        $this->assertTrue($task->fresh()->enabled);
        $data=$this->payload([$other->id,$blocked->id],$second->id);
        $data['allow_reassign']=true;
        $this->post($url,$data)->assertSessionHasErrors('product_ids');
        $this->assertSame((int)$first->id,(int)$task->fresh()->assigned_user_id);
        $this->assertDatabaseCount('product_parameter_tasks',1);
        $this->assertDatabaseCount('product_parameter_submissions',1);
    }

    public function test_invalid_nonexistent_or_unauthorized_factory_fails_without_partial_writes(): void
    {
        $admin=$this->userWith(['products.operate']);
        $noPerm=$this->userWith(['products.view']);
        $factory=$this->userWith(['products.parameters.submit']);
        $p=$this->product('BULK-011');
        $url='/products/parameter-tasks/bulk';
        $this->actingAs($admin)->post($url,$this->payload([$p->id,98765],$factory->id))
            ->assertSessionHasErrors('product_ids.1');
        $this->post($url,$this->payload([$p->id,$p->id],$factory->id))
            ->assertSessionHasErrors('product_ids.1');
        $this->post($url,$this->payload([$p->id],$noPerm->id))
            ->assertSessionHasErrors('assigned_user_id');
        $this->post($url,[
            'product_ids'=>[$p->id],'action'=>'required','assigned_user_id'=>$factory->id,
            'required_fields'=>[],
        ])->assertSessionHasErrors('required_fields');
        $this->post($url,[
            'product_ids'=>[$p->id],'action'=>'required','assigned_user_id'=>$factory->id,
            'required_fields'=>['cost_price'],
        ])->assertSessionHasErrors();
        $this->assertDatabaseCount('product_parameter_tasks',0);
    }

    public function test_manager_grid_shows_assignee_and_only_eligible_factory_staff(): void
    {
        $manager=$this->userWith(['products.view','products.operate']);
        $factory=$this->userWith(['products.parameters.submit']);
        $legacy=$this->userWith(['inventory.packaging.submit']);
        $p=$this->product('BULK-VIEW');
        ProductParameterTask::query()->create([
            'product_id'=>$p->id,'assigned_user_id'=>$factory->id,
            'enabled'=>true,'is_required'=>true,'required_fields'=>['net_weight_kg'],
        ]);
        $this->actingAs($manager)->get('/products/list?view=measurements&search=BULK-VIEW')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Products/Index')
                ->where('products.data.0.id', $p->id)
                ->where('products.data.0.parameter_assigned_user_id',$factory->id)
                ->where('products.data.0.parameter_task_enabled',true)
                ->has('parameterFactoryUsers',1)
                ->where('parameterFactoryUsers.0.id',$factory->id));
        $this->assertNotSame($legacy->id,$factory->id);
    }

    public function test_more_than_hundred_ids_is_rejected(): void
    {
        $admin=$this->userWith(['products.operate']);
        $factory=$this->userWith(['products.parameters.submit']);
        $this->actingAs($admin)->post('/products/parameter-tasks/bulk',
            $this->payload(range(1,101),$factory->id))->assertSessionHasErrors('product_ids');
        $this->assertDatabaseCount('product_parameter_tasks',0);
    }
}
