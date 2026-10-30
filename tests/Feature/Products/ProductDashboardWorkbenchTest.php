<?php

namespace Tests\Feature\Products;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductMedia;
use App\Modules\Products\Infrastructure\Models\ProductParameterSubmission;
use App\Modules\Products\Infrastructure\Models\ProductParameterTask;
use App\Modules\Products\Infrastructure\Models\ProductProviderSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductDashboardWorkbenchTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'dashboard-r1-'.uniqid(), 'label' => 'Product Dashboard UAT']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user->roles()->attach($role);

        return $user;
    }

    private function product(string $sku, array $attrs = []): Product
    {
        return Product::create(array_merge([
            'sku' => $sku,
            'name' => 'Dashboard '.$sku,
            'source_type' => 'manual',
            'product_type' => 'simple',
            'is_active' => true,
        ], $attrs));
    }

    public function test_empty_dashboard_returns_valid_zero_counts_and_empty_recent_list(): void
    {
        $this->actingAs($this->userWith(['products.view']))->get('/products')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Products/Dashboard')
                ->where('totalProducts', 0)
                ->where('metrics.erpActive', 0)
                ->where('metrics.wooPublishedSnapshot', 0)
                ->where('metrics.needsData', 0)
                ->where('metrics.pendingReview', 0)
                ->where('attentionCounts.missing_image', 0)
                ->has('recentProducts', 0)
                ->where('canOperate', false));
    }

    public function test_metrics_are_scoped_to_real_product_fields_and_provider_snapshot(): void
    {
        config(['services.woocommerce.connection_key' => 'beyourlover.com']);
        $good = $this->product('DASH-GOOD', [
            'brand' => 'BeYourLover', 'barcode_69' => '6901234567890',
            'shipping_weight_kg' => '0.6000',
            'length_cm' => 12, 'width_cm' => 9, 'height_cm' => 5,
        ]);
        ProductMedia::create([
            'product_id' => $good->id, 'provider' => 'woocommerce',
            'connection_key' => 'beyourlover.com', 'provider_media_key' => 'img-dashboard',
            'source_url' => 'https://img.beyourlover.com/products/dashboard.webp',
            'is_primary' => true,
        ]);
        ProductProviderSnapshot::create([
            'product_id' => $good->id, 'provider' => 'woocommerce',
            'connection_key' => 'beyourlover.com', 'remote_product_id' => '1001',
            'provider_status' => 'publish', 'payload_hash' => str_repeat('a', 64),
        ]);
        $missing = $this->product('DASH-MISSING', ['is_active' => false]);
        $review = $this->product('DASH-REVIEW', [
            'brand' => 'BeYourLover', 'barcode_69' => '6901234567891',
            'shipping_weight_kg' => '0.5000',
            'length_cm' => 10, 'width_cm' => 5, 'height_cm' => 3,
        ]);
        ProductProviderSnapshot::create([
            'product_id' => $review->id, 'provider' => 'woocommerce',
            'connection_key' => 'another-site.example', 'remote_product_id' => '1002',
            'provider_status' => 'publish', 'payload_hash' => str_repeat('b', 64),
        ]);
        $user = $this->userWith(['products.view']);
        $task = ProductParameterTask::create([
            'product_id' => $review->id, 'assigned_user_id' => $user->id,
            'enabled' => true, 'is_required' => true,
            'required_fields' => ['net_weight_kg'],
        ]);
        ProductParameterSubmission::create([
            'product_id' => $review->id, 'task_id' => $task->id,
            'created_by' => $user->id, 'status' => 'submitted',
            'submitted_at' => now(), 'measurements' => ['net_weight_kg' => '0.5'],
        ]);

        $this->actingAs($user)->get('/products')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Products/Dashboard')
                ->where('totalProducts', 3)
                ->where('metrics.erpActive', 2)
                ->where('metrics.wooPublishedSnapshot', 1)
                ->where('metrics.needsData', 2)
                ->where('metrics.pendingReview', 1)
                ->where('attentionCounts.missing_image', 2)
                ->where('attentionCounts.missing_brand', 1)
                ->where('attentionCounts.missing_barcode_69', 1)
                ->where('attentionCounts.missing_shipping_weight', 1)
                ->where('attentionCounts.missing_dimensions', 1)
                ->where('attentionCounts.pending_review', 1)
                ->has('recentProducts', 3));

        $this->get('/products/list?attention=missing_brand')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Products/Index')
                ->where('filters.attention', 'missing_brand')
                ->where('products.total', 1)
                ->where('products.data.0.id', $missing->id));
        $this->get('/products/list?attention=pending_review')->assertOk()
            ->assertInertia(fn ($page) => $page->where('products.total', 1)
                ->where('products.data.0.id', $review->id));
        $this->get('/products/list?attention=needs_data')->assertOk()
            ->assertInertia(fn ($page) => $page->where('products.total', 2));

        // Barcode-69 search uses the existing safe product list; no new endpoint.
        $this->get('/products/list?search=6901234567890')->assertOk()
            ->assertInertia(fn ($page) => $page->where('products.total', 1)
                ->where('products.data.0.id', $good->id));
    }

    public function test_dashboard_is_read_only_and_does_not_expand_permissions(): void
    {
        $outsider = $this->userWith(['dashboard.view']);
        $this->actingAs($outsider)->get('/products')->assertForbidden();
        $this->actingAs($outsider)->get('/products/list?attention=needs_data')->assertForbidden();

        $viewer = $this->userWith(['products.view']);
        $this->product('READ-ONLY-WORKBENCH');
        $this->actingAs($viewer)->get('/products')->assertOk()
            ->assertInertia(fn ($page) => $page->where('canOperate', false));
        $this->actingAs($viewer)->get('/products/create')->assertForbidden();
        $this->assertDatabaseHas('products', ['sku' => 'READ-ONLY-WORKBENCH']);
    }

    public function test_unknown_attention_value_is_ignored_rather_than_adding_untrusted_sql(): void
    {
        $this->product('SAFE-FILTER-001');
        $user = $this->userWith(['products.view']);
        $this->actingAs($user)->get('/products/list?attention=unknown-injected-value')
            ->assertOk()->assertInertia(fn ($page) => $page->where('products.total', 1));
    }
}
