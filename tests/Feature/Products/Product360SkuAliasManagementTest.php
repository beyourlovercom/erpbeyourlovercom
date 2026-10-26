<?php

namespace Tests\Feature\Products;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Products\Application\SkuAliasService;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductSkuAlias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class Product360SkuAliasManagementTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'alias-detail-'.uniqid(), 'label' => 'Alias details']);
        foreach ($keys as $key) {
            $role->permissions()->firstOrCreate(['key' => $key], ['label' => $key]);
        }
        $user->roles()->attach($role);
        return $user;
    }

    private function product(string $sku): Product
    {
        return Product::create([
            'source_type' => 'manual', 'sku' => $sku,
            'name' => 'SKU '.$sku, 'product_type' => 'simple',
        ]);
    }

    public function test_operator_can_create_verified_identity_alias_without_oms_auto_approval(): void
    {
        $product = $this->product('SKU-WH');
        $user = $this->userWith(['products.view', 'products.operate']);
        $this->actingAs($user)->post("/products/{$product->id}/aliases", [
            'alias_sku' => 'WAREHOUSE-WH', 'source_type' => 'xlwms',
            'source_reference' => 'label:WH', 'notes' => 'Same model and color.',
            'same_identity_confirmed' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $alias = ProductSkuAlias::where('alias_sku', 'WAREHOUSE-WH')->firstOrFail();
        $this->assertSame($product->id, $alias->product_id);
        $this->assertSame('xlwms', $alias->source_type);
        $this->assertNull($alias->oms_verified_at);
        $this->assertDatabaseHas('audit_records', ['action' => 'product.sku_alias.created']);
    }

    public function test_product360_create_fails_in_place_when_alias_collides(): void
    {
        $product = $this->product('SKU-WH');
        $other = $this->product('OTHER');
        app(SkuAliasService::class)->createAlias($other->id, 'WAREHOUSE-WH');
        $user = $this->userWith(['products.view', 'products.operate']);
        $this->actingAs($user)->from("/products/{$product->id}?tab=aliases")
            ->post("/products/{$product->id}/aliases", [
                'alias_sku' => 'warehouse-wh', 'source_type' => 'xlwms',
                'same_identity_confirmed' => true,
            ])
            ->assertRedirect("/products/{$product->id}?tab=aliases")
            ->assertSessionHasErrors('alias_sku');
        $this->assertDatabaseCount('product_sku_aliases', 1);
    }

    public function test_view_only_can_read_product_but_not_mutate_alias(): void
    {
        $product = $this->product('SKU-WH');
        $viewer = $this->userWith(['products.view']);
        $this->actingAs($viewer)->get("/products/{$product->id}")->assertOk();
        $this->actingAs($viewer)->post("/products/{$product->id}/aliases", [
            'alias_sku' => 'WAREHOUSE-WH', 'source_type' => 'xlwms',
            'same_identity_confirmed' => true,
        ])->assertForbidden();
        $this->assertDatabaseCount('product_sku_aliases', 0);
    }

    public function test_alias_status_verification_and_delete_are_product_scoped(): void
    {
        $product = $this->product('SKU-WH');
        $other = $this->product('OTHER');
        $actor = $this->userWith(['products.view', 'products.operate']);
        $service = app(SkuAliasService::class);
        $alias = $service->createAlias($product->id, 'WAREHOUSE-WH');
        $target = "/products/{$product->id}/aliases/{$alias->id}";
        $wrong = "/products/{$other->id}/aliases/{$alias->id}";

        $this->actingAs($actor)->post($wrong.'/verify', [
            'physical_evidence' => 'Model and color confirmed from stock photo.', 'same_identity_confirmed' => true,
        ])->assertNotFound();

        $this->actingAs($actor)->post($target.'/verify', [
            'physical_evidence' => 'Model and color confirmed from stock photo.', 'same_identity_confirmed' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull($alias->fresh()->oms_verified_at);
        $this->assertDatabaseHas('audit_records', ['action' => 'product.sku_alias.oms_verified']);

        $this->actingAs($actor)->patch($target.'/status', ['is_active' => false])->assertRedirect();
        $this->assertFalse($alias->fresh()->is_active);
        $this->assertNull($alias->fresh()->oms_verified_at);
        $this->actingAs($actor)->delete($wrong, ['confirm_delete' => true])->assertNotFound();
        $this->actingAs($actor)->delete($target, ['confirm_delete' => true])->assertRedirect();
        $this->assertDatabaseCount('product_sku_aliases', 0);
        $this->assertDatabaseHas('audit_records', ['action' => 'product.sku_alias.deleted']);
    }

    public function test_alias_identity_confirmation_is_mandatory_even_if_string_matches(): void
    {
        $product = $this->product('SKU-WH');
        $actor = $this->userWith(['products.view', 'products.operate']);
        $this->actingAs($actor)->post("/products/{$product->id}/aliases", [
            'alias_sku' => 'WAREHOUSE-WH', 'source_type' => 'xlwms',
        ])->assertSessionHasErrors('same_identity_confirmed');
        $this->assertDatabaseCount('product_sku_aliases', 0);
    }
}
