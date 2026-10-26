<?php

namespace App\Modules\Products\Application;

use App\Modules\Products\Application\Contracts\SkuAliasRepository;

/**
 * Unified Product OS resolver for warehouse inventory imports and OMS dispatch.
 * A source tag (including historic smgj_pi) is NEVER approval by itself.
 * Canonical Product and manually verified, active aliases are the only inputs.
 * Does not create Products, reserve stock, rewrite SKU or call OMS.
 */
final class VerifiedOmsProductIdentityResolver
{
    public function __construct(
        private readonly SkuIdentityResolver $identity,
        private readonly SkuAliasRepository $aliases
    ) {}

    /** @return array{status:string,product_id:?int,canonical_sku:?string,source:?string,code:string} */
    public function resolve(string $omsSku): array
    {
        $block = static fn (string $code): array => [
            'status'=>'blocked','product_id'=>null,'canonical_sku'=>null,
            'source'=>null,'code'=>$code,
        ];
        $key = SkuMatchKey::compute($omsSku);
        if ($key === null) return $block('OMS_SKU_EMPTY');
        $result = $this->identity->resolve($omsSku);
        if (! $result->isResolved() || ! $result->product || ! $result->canonicalSku) {
            return $block('OMS_PRODUCT_IDENTITY_NOT_FOUND');
        }
        if ($result->type === SkuResolveResult::ALIAS) {
            $alias = $this->aliases->findByMatchKey($key);
            if (! $alias || (int)$alias->getAttribute('product_id') !== (int)$result->product->getKey()
                || $alias->getAttribute('is_active') !== true
                || empty($alias->getAttribute('oms_verified_at'))
                || trim((string)$alias->getAttribute('oms_identity_evidence')) === '') {
                return $block('OMS_ALIAS_PHYSICAL_IDENTITY_NOT_VERIFIED');
            }
        } elseif ($result->type !== SkuResolveResult::CANONICAL) {
            return $block('OMS_PRODUCT_IDENTITY_NOT_FOUND');
        }
        return [
            'status'=>'resolved', 'product_id'=>(int)$result->product->getKey(),
            'canonical_sku'=>$result->canonicalSku,
            'source'=>$result->type,
            'code'=>'OMS_PRODUCT_IDENTITY_VERIFIED',
        ];
    }
}
