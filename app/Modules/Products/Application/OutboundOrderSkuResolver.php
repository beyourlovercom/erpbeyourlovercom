<?php

namespace App\Modules\Products\Application;

use App\Modules\Products\Application\Contracts\SkuAliasRepository;
use RuntimeException;

/**
 * An ERP order alias identifies the same product; it is not a warehouse SKU.
 * Only convert an active, unique alias to its Product's canonical outbound SKU.
 * Physical provider inventory and dispatch approval remain separate gates.
 */
final class OutboundOrderSkuResolver
{
    public function __construct(
        private readonly SkuIdentityResolver $identity,
        private readonly SkuAliasRepository $aliases,
    ) {}

    public function canonical(string $sourceSku, ?int $productId): string
    {
        $sourceSku = trim($sourceSku);
        if ($sourceSku === '' || ! $productId) {
            return $sourceSku;
        }
        $result = $this->identity->resolve($sourceSku);
        if (! $result->isResolved() || ! $result->product
            || (int) $result->product->getKey() !== $productId
            || ! (bool) $result->product->is_active) {
            throw new RuntimeException('OUTBOUND_SKU_PRODUCT_IDENTITY_MISMATCH');
        }
        if ($result->type === SkuResolveResult::ALIAS) {
            $key = SkuMatchKey::compute($sourceSku);
            $alias = $key ? $this->aliases->findByMatchKey($key) : null;
            if (! $alias || ! (bool) $alias->getAttribute('is_active')
                || (int) $alias->getAttribute('product_id') !== $productId) {
                throw new RuntimeException('OUTBOUND_SKU_ALIAS_INACTIVE_OR_AMBIGUOUS');
            }
        } elseif ($result->type !== SkuResolveResult::CANONICAL) {
            throw new RuntimeException('OUTBOUND_SKU_PRODUCT_IDENTITY_MISMATCH');
        }

        return trim((string) $result->canonicalSku);
    }
}
