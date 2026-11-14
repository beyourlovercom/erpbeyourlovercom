<?php

namespace Tests\Feature\Products;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Products\Application\SkuAliasService;
use App\Modules\Products\Application\SkuIdentityResolver;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SkuOwnAliasSearchRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'sku-own-alias-'.uniqid(), 'label' => 'SKU alias regression']);
        foreach ($permissions as $permission) $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        $user->roles()->attach($role);
        return $user;
    }

    private function product(string $sku): Product
    {
        return Product::create(['source_type' => 'manual', 'product_type' => 'simple', 'sku' => $sku, 'name' => 'Lubricant']);
    }

    public function test_own_alias_can_become_canonical_while_previous_sku_remains_resolvable(): void
    {
        $p = $this->product('BY489A-Lubes');
        app(SkuAliasService::class)->createAlias($p->id, 'BY489A', 'inventory_batch');
        $this->actingAs($this->userWith(['products.view', 'products.operate']))
            ->patch("/products/{$p->id}", ['sku' => 'by489a', 'name' => 'Lubricant'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('by489a', $p->fresh()->sku);
        $this->assertDatabaseHas('product_sku_aliases', ['product_id' => $p->id, 'match_key' => 'by489a']);
        $this->assertDatabaseHas('product_sku_aliases', ['product_id' => $p->id, 'match_key' => 'by489a-lubes']);
        $resolver = app(SkuIdentityResolver::class);
        $this->assertSame($p->id, $resolver->resolve('BY489A')->product?->id);
        $this->assertSame($p->id, $resolver->resolve('by489a-lubes')->product?->id);
    }

    public function test_alias_of_another_product_cannot_be_reclaimed(): void
    {
        $p = $this->product('SOME-OTHER-SKU');
        $owner = $this->product('ORIGINAL');
        app(SkuAliasService::class)->createAlias($owner->id, 'BY489A');
        $this->actingAs($this->userWith(['products.view', 'products.operate']))
            ->patch("/products/{$p->id}", ['sku' => 'by489a', 'name' => 'Lubricant'])
            ->assertSessionHasErrors('sku');
        $this->assertSame('SOME-OTHER-SKU', $p->fresh()->sku);
    }

    public function test_case_variants_of_canonical_sku_cannot_create_duplicate_product(): void
    {
        $this->product('BY489A');
        $this->actingAs($this->userWith(['products.view', 'products.operate']))
            ->post('/products', ['sku' => 'by489a', 'name' => 'Duplicate'])
            ->assertSessionHasErrors('sku');
        $this->assertDatabaseCount('products', 1);
    }

    public function test_search_by_alias_is_case_insensitive_and_returns_canonical_product_once(): void
    {
        $this->withoutVite();
        $p = $this->product('BY489A-Lubes');
        app(SkuAliasService::class)->createAlias($p->id, 'BY489A', 'inventory_batch');
        $this->actingAs($this->userWith(['products.view']));
        $results = $this->getJson('/products/sku-search?q=by489a')->assertOk()->json();
        $this->assertCount(1, $results);
        $this->assertSame($p->id, $results[0]['id']);
        $this->assertSame('BY489A-Lubes', $results[0]['sku']);
        $this->assertSame('BY489A', $results[0]['matched_alias']);
        app(SkuAliasService::class)->createAlias($p->id, 'INVENTORY-489A', 'inventory_batch');
        $aliasOnly = $this->getJson('/products/sku-search?q=inventory-489a')->assertOk()->json();
        $this->assertCount(1, $aliasOnly);
        $this->assertSame('INVENTORY-489A', $aliasOnly[0]['matched_alias']);
        $this->get('/products/list?search=inventory-489a')->assertOk()
            ->assertInertia(fn ($page) => $page->where('products.total', 1)
                ->where('products.data.0.id', $p->id)
                ->where('products.data.0.matched_alias', 'INVENTORY-489A'));
    }
}
