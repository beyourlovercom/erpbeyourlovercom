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

final class ProductParameterGridViewsTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $r = Role::create(['key' => 'grid-'.uniqid(), 'label' => 'Product grid viewer']);
        $r->permissions()->firstOrCreate(['key' => 'products.view'], ['label' => 'View Products']);
        $u->roles()->attach($r);
        return $u;
    }

    private function product(string $sku): Product
    {
        return Product::query()->create([
            'sku' => $sku, 'name' => 'Product '.$sku, 'product_type' => 'simple',
            'source_type' => 'manual', 'is_active' => true,
        ]);
    }

    public function test_original_grid_defaults_remain_unchanged_and_views_filter_same_product_rows(): void
    {
        $u = $this->viewer();
        $filled = $this->product('Z-GRID-FILLED');
        $required = $this->product('Z-GRID-REQUIRED');
        $pending = $this->product('Z-GRID-PENDING');
        $other = $this->product('Z-GRID-OTHER');

        $task = ProductParameterTask::query()->create([
            'product_id' => $filled->id, 'assigned_user_id' => $u->id,
            'enabled' => true, 'is_required' => true, 'required_fields' => ['net_weight_kg']]);
        ProductParameterSubmission::query()->create([
            'product_id' => $filled->id, 'task_id' => $task->id, 'created_by' => $u->id,
            'status' => 'approved', 'submitted_at' => now(), 'reviewed_at' => now(),
            'measurements' => ['net_weight_kg' => '1.589', 'material' => '棉+记忆棉内芯',
                'package_length_cm' => 50, 'package_width_cm' => 23, 'package_height_cm' => 19]]);
        ProductParameterAccessory::query()->create([
            'product_id' => $filled->id, 'approved_submission_id' => ProductParameterSubmission::firstOrFail()->id,
            'name' => '小枕头', 'quantity' => 1, 'unit' => 'pcs']);
        ProductParameterTask::query()->create(['product_id' => $required->id, 'enabled' => true,
            'is_required' => true, 'required_fields' => ['net_weight_kg']]);
        ProductParameterTask::query()->create(['product_id' => $pending->id, 'enabled' => true, 'is_required' => false]);

        $this->actingAs($u)->get('/products/list?search=Z-GRID')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Products/Index')
                ->where('filters.view', 'standard')
                ->has('products.data', 4));

        foreach (['已填'=>$filled, '必填'=>$required, '待填'=>$pending, '其他'=>$other] as $status => $p) {
            $url = '/products/list?'.http_build_query(['view'=>'measurements','parameter_status'=>$status]);
            $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->component('Products/Index')
                ->where('filters.parameter_status', $status)
                ->where('products.data.0.id', $p->id)
                ->where('parameterCounts.'.$status, 1));
        }
        $this->get('/products/list?'.http_build_query(['view'=>'measurements','parameter_status'=>'已填']))
            ->assertInertia(fn ($page) => $page->where('products.data.0.net_weight_kg', '1.589')
                ->where('products.data.0.material', '棉+记忆棉内芯')
                ->where('products.data.0.package_dimensions', '50 × 23 × 19 cm')
                ->where('products.data.0.package_volume_l', 21.85));

        $this->get('/products/list?'.http_build_query(['view'=>'accessories','parameter_status'=>'有配件']))
            ->assertInertia(fn ($page) => $page->where('products.data.0.id', $filled->id)
                ->where('products.data.0.accessories_count', 1)
                ->where('products.data.0.accessories_summary', '小枕头 × 1.000 pcs'));
        $this->get('/products/list?'.http_build_query(['view'=>'accessories','parameter_status'=>'待补充']))
            ->assertInertia(fn ($page) => $page->has('products.data', 3));
    }

    public function test_inertia_page_resolution_uses_canonical_case_sensitive_pages_directory(): void
    {
        $this->assertContains(resource_path('js/Pages'), config('inertia.pages.paths'));
        foreach (['Products/Index.vue', 'Products/Show.vue',
            'Products/FactoryParameters.vue', 'Products/FactoryParameterTasks.vue'] as $path) {
            $this->assertFileExists(resource_path('js/Pages/'.$path));
        }
        $this->assertTrue((bool) config('inertia.testing.ensure_pages_exist'));
    }

    public function test_unprivileged_factory_submitter_cannot_access_full_product_grid(): void
    {
        $factory=User::factory()->create(['is_active'=>true]);
        $r=Role::create(['key'=>'factoryonly-'.uniqid(),'label'=>'Factory Only']);
        $r->permissions()->firstOrCreate(['key'=>'products.parameters.submit'],['label'=>'Submit']);
        $factory->roles()->attach($r);
        $this->actingAs($factory)->get('/products/list?view=measurements')->assertForbidden();
    }
}
