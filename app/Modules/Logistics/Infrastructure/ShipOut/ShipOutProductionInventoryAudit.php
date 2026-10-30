<?php

namespace App\Modules\Logistics\Infrastructure\ShipOut;

use App\Modules\Products\Application\VerifiedOmsProductIdentityResolver;
use RuntimeException;

/** R9 audit-only production snapshot. Never reserves or modifies inventory. */
final class ShipOutProductionInventoryAudit
{
    public function __construct(
        private readonly ShipOutProductionReadOnlyGateway $gateway,
        private readonly VerifiedOmsProductIdentityResolver $identity,
    ) {}

    /**
     * Only verified provider warehouse/client organization may be audited.
     * Bounded pagination fails closed instead of presenting partial stock.
     *
     * @return array<string,mixed>
     */
    public function snapshot(int $maxPages = 10): array
    {
        if ($maxPages < 1 || $maxPages > 10) {
            throw new RuntimeException('SHIPOUT_PROD_AUDIT_LIMIT_INVALID');
        }
        $warehouse = trim((string) config('shipout.production_warehouse_id', ''));
        $organization = trim((string) config('shipout.production_client_org_id', ''));
        if ($warehouse === '' || $organization === '') {
            throw new RuntimeException('SHIPOUT_PROD_IDENTITY_UNBOUND');
        }
        $found = false;
        foreach ($this->gateway->warehouses() as $candidate) {
            if ($candidate['warehouseId'] === $warehouse) {
                $found = true;
                break;
            }
        }
        if (! $found) {
            throw new RuntimeException('SHIPOUT_PROD_WAREHOUSE_NOT_AUTHORIZED');
        }
        $products = $this->collectPages(
            fn (int $p) => $this->gateway->products($p, 100), $maxPages
        );
        $stocks = $this->collectPages(
            fn (int $p) => $this->gateway->stock($p, 100), $maxPages
        );
        $catalog = [];
        foreach ($products as $product) {
            $catalog[$product['omsSku']] = $product;
        }
        $records = [];
        $verified = 0;
        $unresolved = 0;
        foreach ($stocks as $stock) {
            $sku = $stock['omsSku'];
            $identity = $this->identity->resolve($sku);
            $mapped = isset($catalog[$sku]) && ($identity['status'] ?? '') === 'resolved';
            if ($mapped) {
                $verified++;
            } else {
                $unresolved++;
            }
            $records[] = [
                'provider_sku' => $sku,
                'available_quantity' => $stock['availableQuantity'],
                'in_product_catalog' => isset($catalog[$sku]),
                'product_status' => $catalog[$sku]['status'] ?? null,
                'sku_alias_status' => $mapped ? 'verified' : 'unresolved',
                'canonical_sku' => $mapped ? $identity['canonical_sku'] : null,
            ];
        }

        return [
            'warehouse_id' => $warehouse,
            'client_org_id' => $organization,
            'product_count' => count($products),
            'stock_sku_count' => count($stocks),
            'verified_sku_count' => $verified,
            'unresolved_sku_count' => $unresolved,
            'stock_rows' => $records,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function collectPages(callable $load, int $maxPages): array
    {
        $all = [];
        $seen = [];
        $expected = null;
        for ($p = 1; $p <= $maxPages; $p++) {
            $page = $load($p);
            if ($expected === null) {
                $expected = $page['total'];
            } elseif ($expected !== $page['total']) {
                throw new RuntimeException('SHIPOUT_PROD_AUDIT_PAGINATION_CHANGED');
            }
            foreach ($page['records'] as $record) {
                $sku = $record['omsSku'];
                if (isset($seen[$sku])) {
                    throw new RuntimeException('SHIPOUT_PROD_AUDIT_DUPLICATE_SKU');
                }
                $seen[$sku] = true;
                $all[] = $record;
            }
            if (count($all) === $expected) {
                return $all;
            }
            if (count($all) > $expected) {
                throw new RuntimeException('SHIPOUT_PROD_AUDIT_PAGINATION_CHANGED');
            }
        }
        throw new RuntimeException('SHIPOUT_PROD_AUDIT_PAGINATION_INCOMPLETE');
    }
}