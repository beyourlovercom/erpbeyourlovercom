<?php

namespace Tests\Feature\Products;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Products\Application\ProductService;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductParameterSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class Product360InlineParametersTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'inline-'.uniqid(), 'label' => 'Product inline']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user->roles()->attach($role);

        return $user;
    }

    private function product(): Product
    {
        return Product::create([
            'source_type' => 'manual', 'product_type' => 'simple',
            'sku' => 'INLINE-'.uniqid(), 'name' => 'Inline test',
            'weight_kg' => 1.0, 'shipping_weight_kg' => 1.2,
            'length_cm' => 10, 'width_cm' => 4, 'height_cm' => 3,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'barcode_69' => '6977291391879',
            'weight_kg' => 1.0, 'shipping_weight_kg' => 1.2,
            'length_cm' => 10, 'width_cm' => 4, 'height_cm' => 3,
            'measurements' => [],
        ], $overrides);
    }

    public function test_operator_can_edit_master_dimensions_and_barcode_without_changing_identity(): void
    {
        $product = $this->product();
        $operator = $this->userWith(['products.view', 'products.operate']);

        $this->actingAs($operator)->put('/products/'.$product->id.'/inline-parameters',
            $this->payload(['length_cm' => 23, 'barcode_69' => '06977291391879', 'name' => 'MALICIOUS',
                'sku' => 'MALICIOUS']))->assertRedirect();

        $product->refresh();
        $this->assertEquals(23, $product->length_cm);
        $this->assertSame('06977291391879', $product->barcode_69);
        $this->assertSame('Inline test', $product->name);
        $this->assertStringStartsWith('INLINE-', $product->sku);
        $this->assertDatabaseCount('product_parameter_submissions', 0);
    }

    public function test_battery_defaults_to_yes_and_can_be_toggled_exclusively_by_operator(): void
    {
        $product = $this->product();
        $operator = $this->userWith(['products.view', 'products.operate']);
        $this->assertTrue($product->fresh()->has_battery);
        $this->actingAs($operator)->put('/products/'.$product->id.'/inline-parameters',
            $this->payload(['has_battery' => false]))->assertRedirect();
        $this->assertFalse($product->fresh()->has_battery);
        $this->get('/products/'.$product->id)->assertInertia(fn ($page) => $page->where('product.has_battery', false)
            ->where('product.brand', null)
            ->where('product.sku', $product->sku));

        $this->actingAs($operator)->put('/products/'.$product->id.'/inline-parameters',
            $this->payload(['has_battery' => true]))->assertRedirect();
        $this->assertTrue($product->fresh()->has_battery);

        $this->actingAs($operator)->put('/products/'.$product->id.'/inline-parameters',
            $this->payload(['has_battery' => 'maybe', 'length_cm' => 26]))
            ->assertSessionHasErrors('has_battery');
        $this->assertEquals(10, $product->fresh()->length_cm);
    }

    public function test_new_manual_products_default_only_exact_approved_liquid_skus_to_true(): void
    {
        $service = app(ProductService::class);
        foreach (['BY489A', 'BV339A', 'BV339A-P', 'BV339A-Y'] as $sku) {
            $product = $service->createManual(['sku' => $sku, 'name' => $sku.' UAT']);
            $this->assertTrue($product->fresh()->has_liquid, $sku);
            $this->assertNull($product->fresh()->material_full_name);
        }
        foreach (['MF414A', 'BV339A-Z', 'BY489A-P'] as $sku) {
            $product = $service->createManual(['sku' => $sku, 'name' => $sku.' UAT']);
            $this->assertFalse($product->fresh()->has_liquid, $sku);
        }
    }

    public function test_variant_backfill_migration_marks_only_existing_approved_skus(): void
    {
        foreach (['BY489A', 'BV339A-P', 'BV339A-Y', 'BV339A-Z'] as $sku) {
            Product::create(['sku' => $sku, 'name' => $sku, 'has_liquid' => false]);
        }
        $migration = require database_path('migrations/2026_11_19_150000_mark_bv339a_variants_as_liquid.php');
        $migration->up();
        $this->assertTrue((bool) Product::where('sku', 'BV339A-P')->value('has_liquid'));
        $this->assertTrue((bool) Product::where('sku', 'BV339A-Y')->value('has_liquid'));
        $this->assertFalse((bool) Product::where('sku', 'BV339A-Z')->value('has_liquid'));
        $this->assertFalse((bool) Product::where('sku', 'BY489A')->value('has_liquid'));
        $migration->down();
        $this->assertTrue((bool) Product::where('sku', 'BV339A-P')->value('has_liquid'));
    }

    public function test_manual_liquid_override_remains_permitted(): void
    {
        $product = app(ProductService::class)->createManual(['sku' => 'BV339A-P', 'name' => 'Override', 'has_liquid' => false]);
        $this->assertFalse($product->fresh()->has_liquid);
    }

    public function test_liquid_and_full_material_name_are_erp_owned_editable_master_fields(): void
    {
        $product = $this->product();
        $operator = $this->userWith(['products.view', 'products.operate']);
        $this->assertFalse($product->fresh()->has_liquid);
        $this->assertNull($product->fresh()->material_full_name);
        $fullName = 'BV339A 胸部按摩仪成品 紫色 / BEYOURLOVER';

        $this->actingAs($operator)->put('/products/'.$product->id.'/inline-parameters',
            $this->payload(['has_liquid' => true, 'material_full_name' => $fullName]))->assertRedirect();
        $this->assertTrue($product->fresh()->has_liquid);
        $this->assertSame($fullName, $product->fresh()->material_full_name);
        $this->get('/products/'.$product->id)->assertInertia(fn ($page) => $page
            ->where('product.has_liquid', true)->where('product.material_full_name', $fullName));

        $this->put('/products/'.$product->id.'/inline-parameters',
            $this->payload(['has_liquid' => 'maybe', 'material_full_name' => 'Cannot save']))
            ->assertSessionHasErrors('has_liquid');
        $this->assertSame($fullName, $product->fresh()->material_full_name);
        $this->actingAs($this->userWith(['products.view']))
            ->put('/products/'.$product->id.'/inline-parameters',
                $this->payload(['has_liquid' => false, 'material_full_name' => 'Viewer edit']))->assertForbidden();
        $this->assertTrue($product->fresh()->has_liquid);
    }

    public function test_inline_weight_update_preserves_canonical_weight_evidence(): void
    {
        $product = $this->product();
        $operator = $this->userWith(['products.view', 'products.operate']);
        $this->actingAs($operator)->put('/products/'.$product->id.'/inline-parameters',
            $this->payload(['weight_kg' => 1.5, 'shipping_weight_kg' => 1.8]))->assertRedirect();
        $product->refresh();
        $this->assertSame('1.5000', (string) $product->weight_kg);
        $this->assertSame('1.8000', (string) $product->shipping_weight_kg);
        $this->assertNotNull($product->weight_evidence_id);
        $this->assertNotNull($product->shipping_weight_evidence_id);
    }

    public function test_factory_edits_enter_review_queue_and_do_not_overwrite_approved_measurements(): void
    {
        $product = $this->product();
        ProductParameterSubmission::create([
            'product_id' => $product->id, 'created_by' => $this->userWith(['products.view'])->id,
            'status' => 'approved', 'measurements' => ['material' => 'Old approved'], 'accessories' => [],
            'reviewed_at' => now(),
        ]);
        $operator = $this->userWith(['products.view', 'products.operate']);
        $this->actingAs($operator)->put('/products/'.$product->id.'/inline-parameters',
            $this->payload(['measurements' => ['material' => 'New pending', 'package_length_cm' => 18]]))
            ->assertRedirect();
        $pending = ProductParameterSubmission::where('product_id', $product->id)
            ->where('status', 'submitted')->firstOrFail();
        $this->assertSame((int) $operator->id, (int) $pending->created_by);
        $this->assertNull($pending->reviewed_at);
        $this->assertSame('New pending', $pending->measurements['material']);
        $this->get('/products/'.$product->id)->assertInertia(fn ($page) => $page->where('approvedParameterMeasurements.material', 'Old approved'));
    }

    public function test_viewer_and_unassigned_factory_account_cannot_save(): void
    {
        $product = $this->product();
        foreach (['products.view', 'products.parameters.submit'] as $permission) {
            $this->actingAs($this->userWith([$permission]))
                ->put('/products/'.$product->id.'/inline-parameters', $this->payload())->assertForbidden();
        }
        $this->assertNull($product->fresh()->barcode_69);
    }

    public function test_invalid_factory_measurement_or_barcode_blocks_entire_update(): void
    {
        $product = $this->product();
        $this->actingAs($this->userWith(['products.operate']))
            ->put('/products/'.$product->id.'/inline-parameters',
                $this->payload(['barcode_69' => 'x', 'length_cm' => 25,
                    'measurements' => ['net_weight_kg' => -1]]))
            ->assertSessionHasErrors(['barcode_69', 'measurements.net_weight_kg']);
        $this->assertEquals(10, $product->fresh()->length_cm);
        $this->assertDatabaseCount('product_parameter_submissions', 0);
    }
}
