<?php

namespace App\Modules\Logistics\Application\ShipOut;

use App\Modules\Products\Application\VerifiedOmsProductIdentityResolver;
use RuntimeException;

/**
 * US-LA read-only stock preview. Existing Product OS verification is mandatory.
 * No inventory/reservation/lot/product writes.
 */
final class ShipOutMorePlusStockPreflight
{
    public function __construct(private readonly VerifiedOmsProductIdentityResolver $identity) {}

    /** @param list<array<string,mixed>> $records */
    public function preview(array $records): array
    {
        $seenSkus = [];
        $seenProducts = [];
        $rows = [];
        $matched = $blocked = 0;
        foreach ($records as $row) {
            $sku = trim((string) ($row['omsSku'] ?? ''));
            if ($sku === '' || isset($seenSkus[$sku]) || ! isset($row['availableQuantity'])
                || ! is_int($row['availableQuantity']) || $row['availableQuantity'] < 0) {
                throw new RuntimeException('SHIPOUT_PREVIEW_UNTRUSTED_ROW');
            }
            $seenSkus[$sku] = true;
            $result = $this->identity->resolve($sku);
            if (($result['status'] ?? null) !== 'resolved' || empty($result['product_id'])) {
                $blocked++;
                $rows[] = [
                    'oms_sku' => $sku, 'status' => 'blocked',
                    'reason' => $result['code'] ?? 'OMS_PRODUCT_IDENTITY_NOT_VERIFIED',
                    'available' => $row['availableQuantity'],
                ];
                continue;
            }
            $id = (int) $result['product_id'];
            if (isset($seenProducts[$id])) {
                throw new RuntimeException('SHIPOUT_PREVIEW_MULTIPLE_SKUS_ONE_PRODUCT');
            }
            $seenProducts[$id] = true;
            $matched++;
            $rows[] = [
                'oms_sku' => $sku, 'canonical_sku' => $result['canonical_sku'],
                'product_id' => $id, 'available' => $row['availableQuantity'],
                'status' => 'matched',
            ];
        }
        return [
            'warehouse_code' => 'US-LA',
            'status' => $records === [] ? 'unknown_empty' : ($blocked ? 'blocked' : 'read_only_ready'),
            'matched' => $matched, 'blocked' => $blocked, 'rows' => $rows,
        ];
    }
}
