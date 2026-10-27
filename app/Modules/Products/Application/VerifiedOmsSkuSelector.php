<?php

namespace App\Modules\Products\Application;

/**
 * Pure, fail-closed OMS candidate selector. Does not read OMS, mutate an
 * order, or grant alias verification. Its caller MUST supply a complete,
 * warehouse-scoped catalog snapshot with independently verified physical
 * product equivalence and stock. Never supply raw vendor rows here.
 *
 * The actual OMS adapter and dispatch wiring remain a separate UAT gate.
 */
final class VerifiedOmsSkuSelector
{
    /**
     * @param list<array<string,mixed>> $approvedAliases Verified ProductSkuAlias rows only
     * @param list<array<string,mixed>> $catalog Complete, trusted adapter-normalized rows:
     *   sku, warehouse_code, approval_status (2 approved), available_quantity,
     *   physical_identity_confirmed (true only after documented physical check).
     * @return array{status:string,code:string,oms_sku:?string,source:?string}
     */
    public function select(
        string $canonicalSku,
        array $approvedAliases,
        string $warehouseCode,
        int $quantity,
        array $catalog,
        bool $catalogComplete
    ): array {
        $block = static fn (string $code): array => [
            'status' => 'blocked', 'code' => $code, 'oms_sku' => null, 'source' => null,
        ];

        if (trim($warehouseCode) === '' || $quantity < 1
            || SkuMatchKey::compute($canonicalSku) === null) {
            return $block('OMS_SKU_INPUT_INVALID');
        }
        if (! $catalogComplete) {
            return $block('OMS_CATALOG_INCOMPLETE');
        }

        $candidateKeys = [SkuMatchKey::compute($canonicalSku) => 'canonical'];
        foreach ($approvedAliases as $alias) {
            if (($alias['is_active'] ?? null) !== true
                || empty($alias['oms_verified_at'])
                || trim((string) ($alias['oms_identity_evidence'] ?? '')) === '') {
                continue;
            }
            $key = SkuMatchKey::compute((string) ($alias['alias_sku'] ?? ''));
            if ($key === null || isset($candidateKeys[$key])) {
                continue;
            }
            $candidateKeys[$key] = 'approved_alias';
        }

        $hits = [];
        foreach ($catalog as $row) {
            if (! is_array($row) || ($row['warehouse_code'] ?? null) !== $warehouseCode) {
                continue;
            }
            $sku = trim((string) ($row['sku'] ?? ''));
            $key = SkuMatchKey::compute($sku);
            if ($key !== null && isset($candidateKeys[$key])) {
                $hits[] = ['sku' => $sku, 'source' => $candidateKeys[$key], 'row' => $row];
            }
        }

        if (count($hits) === 0) {
            return $block('OMS_SKU_NOT_FOUND');
        }
        // All simultaneously visible OMS codes require manual disambiguation,
        // even if only one has apparent stock or approval.
        if (count($hits) !== 1) {
            return $block('OMS_SKU_AMBIGUOUS');
        }

        $match = $hits[0];
        $row = $match['row'];
        if (! array_key_exists('approval_status', $row) || (int) $row['approval_status'] !== 2) {
            return $block('OMS_SKU_UNAPPROVED');
        }
        if (($row['physical_identity_confirmed'] ?? null) !== true) {
            return $block('OMS_PHYSICAL_IDENTITY_UNVERIFIED');
        }
        if (! isset($row['available_quantity']) || ! is_numeric($row['available_quantity'])) {
            return $block('OMS_STOCK_UNVERIFIED');
        }
        if ((float) $row['available_quantity'] < $quantity) {
            return $block('OMS_STOCK_INSUFFICIENT');
        }

        return [
            'status' => 'ready',
            'code' => 'OMS_SKU_UNIQUE_VERIFIED',
            'oms_sku' => $match['sku'],
            'source' => $match['source'],
        ];
    }
}
