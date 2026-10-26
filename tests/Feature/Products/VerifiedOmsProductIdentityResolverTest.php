<?php

namespace Tests\Feature\Products;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Products\Application\VerifiedOmsProductIdentityResolver;
use App\Modules\Products\Application\SkuAliasService;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VerifiedOmsProductIdentityResolverTest extends TestCase
{
    use RefreshDatabase;

    private function product(): Product
    {
        return Product::create([
            'source_type'=>'manual','sku'=>'MODEL-001-WHITE',
            'name'=>'White model 001','product_type'=>'simple',
        ]);
    }

    private function operator(): User
    {
        $user=User::factory()->create(['is_active'=>true]);
        $role=Role::create(['key'=>'oms-alias-test-'.uniqid(),'label'=>'OMS Product Alias']);
        $role->permissions()->firstOrCreate(['key'=>'products.operate'], ['label'=>'Product operate']);
        $user->roles()->attach($role);
        return $user;
    }

    public function test_canonical_sku_resolves_without_any_alias(): void
    {
        $product=$this->product();
        $result=app(VerifiedOmsProductIdentityResolver::class)->resolve('model-001-white');
        self::assertSame('resolved',$result['status']);
        self::assertSame($product->id,$result['product_id']);
        self::assertSame('canonical',$result['source']);
    }

    public function test_historic_pi_source_is_not_sufficient_approval(): void
    {
        $product=$this->product();
        app(SkuAliasService::class)->createAlias($product->id,'LEGACY-WHITE','smgj_pi','historic-pi');
        $result=app(VerifiedOmsProductIdentityResolver::class)->resolve('LEGACY-WHITE');
        self::assertSame('blocked',$result['status']);
        self::assertSame('OMS_ALIAS_PHYSICAL_IDENTITY_NOT_VERIFIED',$result['code']);
    }

    public function test_manual_verification_allows_same_physical_product_alias(): void
    {
        $product=$this->product();
        $actor=$this->operator();
        $svc=app(SkuAliasService::class);
        $alias=$svc->createAlias($product->id,'WAREHOUSE-WHITE','xlwms','same-white-product');
        $svc->verifyForOms($alias,'Verified same model, white color, identical package and label.', $actor);
        $result=app(VerifiedOmsProductIdentityResolver::class)->resolve('warehouse-white');
        self::assertSame('resolved',$result['status']);
        self::assertSame($product->id,$result['product_id']);
        self::assertSame('alias',$result['source']);
    }

    public function test_deactivation_blocks_identity_and_reactivation_requires_reverification(): void
    {
        $product=$this->product();$actor=$this->operator();
        $svc=app(SkuAliasService::class);$alias=$svc->createAlias($product->id,'WAREHOUSE-WHITE');
        $svc->verifyForOms($alias,'Same SKU physical model and white color verified in person.',$actor);
        $svc->setActive($alias,false,$actor);
        self::assertSame('OMS_PRODUCT_IDENTITY_NOT_FOUND',app(VerifiedOmsProductIdentityResolver::class)->resolve('WAREHOUSE-WHITE')['code']);
        $svc->setActive($alias,true,$actor);
        self::assertSame('OMS_ALIAS_PHYSICAL_IDENTITY_NOT_VERIFIED',app(VerifiedOmsProductIdentityResolver::class)->resolve('WAREHOUSE-WHITE')['code']);
    }

    public function test_unrelated_sku_or_color_never_fuzzy_resolves(): void
    {
        $this->product();
        self::assertSame('OMS_PRODUCT_IDENTITY_NOT_FOUND',app(VerifiedOmsProductIdentityResolver::class)->resolve('MODEL-001-BLACK')['code']);
        self::assertSame('OMS_SKU_EMPTY',app(VerifiedOmsProductIdentityResolver::class)->resolve(' ')['code']);
    }
}
