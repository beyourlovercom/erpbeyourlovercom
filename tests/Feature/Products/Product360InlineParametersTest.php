<?php

namespace Tests\Feature\Products;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
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
        $this->get('/products/'.$product->id)->assertInertia(fn ($page) =>
            $page->where('approvedParameterMeasurements.material', 'Old approved'));
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
