<?php

namespace Tests\Feature\Products;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

final class ProductBarcode69Test extends TestCase
{
    use RefreshDatabase;

    private function authorizedUser(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'barcode-test-'.uniqid(), 'label' => 'Barcode QA']);
        $role->permissions()->firstOrCreate(['key' => 'products.operate'], ['label' => 'products.operate']);
        $user->roles()->attach($role);
        return $user;
    }

    private function product(string $sku, ?string $barcode = null): Product
    {
        return Product::create([
            'source_type' => 'manual',
            'product_type' => 'simple',
            'sku' => $sku,
            'name' => $sku,
            'barcode_69' => $barcode,
        ]);
    }

    private function upload(string $csv): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('barcode69.csv', $csv);
    }

    public function test_preview_does_not_write_and_confirm_only_fills_empty_values(): void
    {
        $empty = $this->product('VX357A');
        $existing = $this->product('VX357B', '6977291390223');
        $conflict = $this->product('VX357D', '1111111111111');
        $csv = "sku,barcode_69\nVX357A,6977291390537\nVX357B,6977291390223\nVX357D,6977291390582\nUNKNOWN,6977291391234\n";
        $user = $this->authorizedUser();

        $response = $this->actingAs($user)->postJson('/products/barcode-69/import/preview', [
            'file' => $this->upload($csv),
        ])->assertOk()->assertJsonPath('ready_count', 1);

        $this->assertNull($empty->fresh()->barcode_69);
        $this->assertSame('6977291390223', $existing->fresh()->barcode_69);
        $this->assertSame('1111111111111', $conflict->fresh()->barcode_69);

        $fingerprint = $response->json('fingerprint');
        $this->actingAs($user)->postJson('/products/barcode-69/import/confirm', [
            'fingerprint' => $fingerprint,
        ])->assertOk()->assertJsonPath('updated', 1);

        $this->assertSame('6977291390537', $empty->fresh()->barcode_69);
        $this->assertSame('6977291390223', $existing->fresh()->barcode_69);
        $this->assertSame('1111111111111', $conflict->fresh()->barcode_69);
    }

    public function test_preview_rejects_non_numeric_barcode(): void
    {
        $this->actingAs($this->authorizedUser())
            ->postJson('/products/barcode-69/import/preview', [
                'file' => $this->upload("sku,barcode_69\nVX357A,697ABC\n"),
            ])->assertStatus(422);
    }

    public function test_import_rejects_user_without_products_operate_permission(): void
    {
        $this->postJson('/products/barcode-69/import/preview', [])->assertRedirect();
    }
}
