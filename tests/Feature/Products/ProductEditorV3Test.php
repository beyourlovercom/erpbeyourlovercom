<?php

namespace Tests\Feature\Products;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductMedia;
use App\Modules\Products\Infrastructure\Models\ProductParameterSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ProductEditorV3Test extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'editor-v3-'.uniqid(), 'label' => 'Product Editor V3']);
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
            'sku' => 'V3-'.uniqid(), 'name' => 'Product Editor V3',
        ]);
    }
    public function test_create_with_images_barcode_brand_and_battery_persists_primary(): void
    {
        Storage::fake('local');
        $this->actingAs($this->userWith(['products.view', 'products.operate']))
            ->post('/products', [
                'sku' => 'EDITOR-V3-CREATE', 'name' => 'Editor create',
                'barcode_69' => '06977291391879', 'brand' => 'BYL',
                'has_battery' => false, 'primary_index' => 1,
                'images' => [UploadedFile::fake()->image('front.png'), UploadedFile::fake()->image('back.jpg')],
            ])->assertRedirect();

        $product = Product::query()->where('sku', 'EDITOR-V3-CREATE')->firstOrFail();
        $this->assertSame('06977291391879', $product->barcode_69);
        $this->assertSame('BYL', $product->brand);
        $this->assertFalse($product->has_battery);
        $media = ProductMedia::query()->where('product_id', $product->id)->orderBy('position')->get();
        $this->assertCount(2, $media);
        $this->assertSame('erp_manual', $media[0]->provider);
        $this->assertFalse($media[0]->is_primary);
        $this->assertTrue($media[1]->is_primary);
        Storage::disk('local')->assertExists($media[0]->storage_path);
        Storage::disk('local')->assertExists($media[1]->storage_path);
        $this->get('/products/'.$product->id.'/edit')->assertOk()
            ->assertInertia(fn ($page) => $page->where('product.barcode_69', '06977291391879')
                ->where('product.brand', 'BYL')->has('product.media', 2)->has('packagingPresets', 4));
    }

    public function test_invalid_barcode_rejects_product_and_files(): void
    {
        Storage::fake('local');
        $this->actingAs($this->userWith(['products.operate']))
            ->post('/products', ['sku' => 'V3-NOT-CREATED', 'name' => 'Invalid',
                'barcode_69' => 'BAD', 'images' => [UploadedFile::fake()->image('bad.png')]])
            ->assertSessionHasErrors('barcode_69');
        $this->assertDatabaseMissing('products', ['sku' => 'V3-NOT-CREATED']);
    }
    public function test_manual_media_order_primary_and_delete_preserve_woocommerce_media(): void
    {
        Storage::fake('local');
        $product = $this->product();
        $operator = $this->userWith(['products.view', 'products.operate']);
        $viewer = $this->userWith(['products.view']);

        $wooPath = UploadedFile::fake()->image('woo.png')->store('woocommerce', 'local');
        $woo = ProductMedia::create([
            'product_id' => $product->id, 'provider' => 'woocommerce', 'connection_key' => 'beyourlover.com',
            'provider_media_key' => 'woo-1', 'source_url' => 'https://example.com/woo.png',
            'storage_path' => $wooPath, 'mime_type' => 'image/png',
            'size_bytes' => 10, 'sha256' => str_repeat('a', 64), 'position' => 0, 'is_primary' => true,
        ]);
        $this->actingAs($operator)->post('/products/'.$product->id.'/media', [
            'images' => [UploadedFile::fake()->image('one.png'), UploadedFile::fake()->image('two.png')],
        ])->assertRedirect();
        $manual = ProductMedia::where('product_id', $product->id)
            ->where('provider', 'erp_manual')->orderBy('position')->get();
        $this->assertCount(2, $manual);
        $this->assertTrue($manual[0]->is_primary);
        $this->patch('/products/'.$product->id.'/media', [
            'media' => [
                ['id' => $woo->id, 'position' => 0],
                ['id' => $manual[0]->id, 'position' => 1],
            ],
            'primary_id' => $woo->id,
        ])->assertSessionHasErrors('media');
        $this->assertTrue($manual[0]->fresh()->is_primary);

        $this->patch('/products/'.$product->id.'/media', [
            'media' => [
                ['id' => $manual[1]->id, 'position' => 0],
                ['id' => $manual[0]->id, 'position' => 1],
            ],
            'primary_id' => $manual[1]->id,
        ])->assertRedirect();
        $this->assertTrue($manual[1]->fresh()->is_primary);
        $this->get('/products/'.$product->id)->assertInertia(fn ($page) =>
            $page->where('product.image_url', route('products.media.show', $manual[1]->id)));
        $this->actingAs($viewer)->get('/products/media/'.$manual[1]->id)->assertOk();
        $this->delete('/products/'.$product->id.'/media/'.$manual[1]->id)->assertForbidden();
        $this->actingAs($operator)->delete('/products/'.$product->id.'/media/'.$manual[1]->id)->assertRedirect();
        $this->assertTrue($manual[0]->fresh()->is_primary);
        $this->assertDatabaseHas('product_media', ['id' => $woo->id, 'provider' => 'woocommerce']);
        Storage::disk('local')->assertExists($wooPath);
    }
    public function test_measurement_change_is_pending_and_does_not_mutate_approved_value(): void
    {
        $product = $this->product();
        $operator = $this->userWith(['products.view', 'products.operate']);
        ProductParameterSubmission::create([
            'product_id' => $product->id, 'created_by' => $operator->id,
            'status' => 'approved', 'measurements' => ['material' => 'Old approved'],
            'accessories' => [], 'reviewed_at' => now(),
        ]);
        $this->actingAs($operator)->post('/products/'.$product->id.'/measurement-submissions', [
            'measurements' => ['material' => 'New pending', 'carton_length_cm' => 40],
        ])->assertRedirect();
        $pending = ProductParameterSubmission::where('product_id', $product->id)
            ->where('status', 'submitted')->firstOrFail();
        $this->assertSame('New pending', $pending->measurements['material']);
        $this->assertNull($pending->reviewed_at);
        $this->get('/products/'.$product->id.'/edit')->assertInertia(fn ($page) =>
            $page->where('approvedMeasurements.material', 'Old approved')
                ->where('pendingParameterReviewCount', 1));
        $this->post('/products/'.$product->id.'/measurement-submissions',
            ['measurements' => ['carton_length_cm' => -1]])->assertSessionHasErrors('measurements.carton_length_cm');
    }

    public function test_read_only_role_cannot_mutate_media_or_factory_parameters(): void
    {
        $product = $this->product();
        $this->actingAs($this->userWith(['products.view']))
            ->post('/products/'.$product->id.'/media', [
                'images' => [UploadedFile::fake()->image('denied.png')],
            ])->assertForbidden();
        $this->post('/products/'.$product->id.'/measurement-submissions',
            ['measurements' => ['material' => 'Bypass']])->assertForbidden();
        $this->assertDatabaseCount('product_parameter_submissions', 0);
        $this->assertDatabaseCount('product_media', 0);
    }
}
