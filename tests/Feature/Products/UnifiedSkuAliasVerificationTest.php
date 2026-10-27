<?php

namespace Tests\Feature\Products;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Products\Application\SkuAliasService;
use App\Modules\Products\Application\SkuIdentityResolver;
use App\Modules\Products\Application\SkuResolveResult;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UnifiedSkuAliasVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $sku): Product
    {
        return Product::create([
            'source_type' => 'manual', 'sku' => $sku, 'name' => 'Product '.$sku,
            'product_type' => 'simple',
        ]);
    }

    private function operator(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'alias-operator-'.uniqid(), 'label' => 'Alias Operator']);
        $role->permissions()->firstOrCreate(['key' => 'products.operate'], ['label' => 'Product operate']);
        $user->roles()->attach($role);
        return $user;
    }

    public function test_existing_alias_is_not_automatically_approved_for_oms(): void
    {
        $product = $this->product('ABC-001-WH');
        $service = app(SkuAliasService::class);
        $alias = $service->createAlias($product->id, 'A001-W', 'legacy_import');
        $this->assertTrue($alias->fresh()->is_active);
        $this->assertNull($alias->fresh()->oms_verified_at);
        $this->assertSame([], $service->approvedAliasesForOms($product->id));
        $this->assertSame(
            SkuResolveResult::ALIAS,
            app(SkuIdentityResolver::class)->resolve('A001-W')->type
        );
    }

    public function test_only_operator_can_attest_documented_physical_equivalence(): void
    {
        $product = $this->product('ABC-001-WH');
        $service = app(SkuAliasService::class);
        $alias = $service->createAlias($product->id, 'A001-W');
        $viewer = User::factory()->create(['is_active' => true]);

        try {
            $service->verifyForOms($alias, 'Same model/color/packaging verified by warehouse.', $viewer);
            $this->fail('Viewer must not be able to approve OMS use');
        } catch (\RuntimeException $error) {
            $this->assertSame('SKU_ALIAS_VERIFICATION_FORBIDDEN', $error->getMessage());
        }
        $this->assertNull($alias->fresh()->oms_verified_at);

        $operator = $this->operator();
        $service->verifyForOms($alias, 'Same model, color and packaging from signed warehouse photo.', $operator);
        $rows = $service->approvedAliasesForOms($product->id);
        $this->assertCount(1, $rows);
        $this->assertSame('A001-W', $rows[0]['alias_sku']);
        $this->assertDatabaseHas('audit_records', ['action' => 'product.sku_alias.oms_verified']);
    }

    public function test_disable_reserves_the_alias_but_blocks_resolver_and_reapproval_is_required(): void
    {
        $product = $this->product('ABC-001-WH');
        $service = app(SkuAliasService::class);
        $operator = $this->operator();
        $alias = $service->createAlias($product->id, 'A001-W');
        $service->verifyForOms($alias, 'Same SKU and physical attributes confirmed by warehouse.', $operator);
        $service->setActive($alias, false, $operator);
        $this->assertSame([], $service->approvedAliasesForOms($product->id));
        $this->assertSame(
            SkuResolveResult::UNMATCHED,
            app(SkuIdentityResolver::class)->resolve('A001-W')->type
        );

        $this->expectException(\RuntimeException::class);
        $service->createAlias($this->product('OTHER-WH')->id, 'A001-W');
    }

    public function test_reactivate_does_not_resurrect_oms_approval(): void
    {
        $product = $this->product('ABC-001-WH');
        $service = app(SkuAliasService::class);
        $operator = $this->operator();
        $alias = $service->createAlias($product->id, 'A001-W');
        $service->verifyForOms($alias, 'Physical model, color and packaging were verified.', $operator);
        $service->setActive($alias, false, $operator);
        $service->setActive($alias, true, $operator);
        $this->assertSame([], $service->approvedAliasesForOms($product->id));
        $this->assertSame(SkuResolveResult::ALIAS, app(SkuIdentityResolver::class)->resolve('A001-W')->type);
    }
}
