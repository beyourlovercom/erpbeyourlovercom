<?php

namespace App\Modules\Products\Application;

/**
 * Read-only bridge to the XLWMS full-catalog + integrated-inventory snapshot
 * contract. No OMS network, inventory, order or dispatch writes are permitted.
 * Inputs MUST originate from trusted ERP services, never from browser fields.
 *
 * Readiness is only a candidate/stock check, NOT dispatch authorization:
 * the OMS service must still execute its independent duplicate, channel,
 * live SKU approval and exact single-order isSubmit gates before any write.
 */
final class VerifiedOmsSnapshotPreflight
{
    private const MAX_SNAPSHOT_AGE_SECONDS = 120;

    public function __construct(private readonly VerifiedOmsSkuSelector $selector) {}

    /**
     * @param array<string,mixed> $snapshot Snapshot metadata from the existing XLWMS InventorySync V2
     * @param list<array<string,mixed>> $rows Complete persisted snapshot rows
     * @param list<array{product_id:int,canonical_sku:string,quantity:int}> $lines Canonical ERP products from server DB
     * @param array<int,list<array<string,mixed>>> $approvedAliasesByProduct Verified Product OS aliases keyed by product_id
     * @param list<string> $liveApprovedOmsSkus Exact codes proven by a fresh read-only OMS approval check
     * @return array{status:string,code:string,items:list<array{product_id:int,canonical_sku:string,oms_sku:string,quantity:int}>}
     */
    public function forOrder(
        int $warehouseId,
        string $warehouseCode,
        array $snapshot,
        array $rows,
        array $lines,
        array $approvedAliasesByProduct,
        array $liveApprovedOmsSkus,
        int $nowUnix
    ): array {
        $blocked = static fn (string $code): array => ['status'=>'blocked','code'=>$code,'items'=>[]];
        if ($warehouseId < 1 || ($snapshot['warehouse_id'] ?? null) !== $warehouseId
            || trim($warehouseCode) === '' || ($snapshot['wh_code'] ?? null) !== $warehouseCode) {
            return $blocked('OMS_SNAPSHOT_WAREHOUSE_MISMATCH');
        }
        // Only an applied and lot-reconciled V2 snapshot is allocation-safe.
        if (($snapshot['status'] ?? null) !== 'applied'
            || ($snapshot['summary']['v2'] ?? null) !== true
            || ($snapshot['summary']['v2_applied'] ?? null) !== true
            || (int) ($snapshot['unmatched_sku_count'] ?? -1) !== 0
            || (int) ($snapshot['blocked_sku_count'] ?? -1) !== 0) {
            return $blocked('OMS_SNAPSHOT_NOT_RECONCILED');
        }
        $checkedAt = $snapshot['last_checked_at'] ?? null;
        $checkedUnix = is_string($checkedAt) ? strtotime($checkedAt) : false;
        if ($checkedUnix === false || $checkedUnix > $nowUnix
            || $nowUnix - $checkedUnix > self::MAX_SNAPSHOT_AGE_SECONDS) {
            return $blocked('OMS_SNAPSHOT_NOT_FRESH');
        }
        if (! is_int($snapshot['provider_sku_count'] ?? null)
            || $snapshot['provider_sku_count'] < 1
            || $snapshot['provider_sku_count'] !== count($rows)
            || ! is_int($snapshot['summary']['oms_rows'] ?? null)) {
            return $blocked('OMS_SNAPSHOT_INCOMPLETE');
        }
        $catalog = [];
        $keys = [];
        $stockRowCount = 0;
        foreach ($rows as $row) {
            $sku = trim((string) ($row['provider_sku'] ?? ''));
            $key = SkuMatchKey::compute($sku);
            if ($key === null || isset($keys[$key])) return $blocked('OMS_SNAPSHOT_DUPLICATE_SKU');
            $keys[$key] = true;
            $status = $row['resolution_status'] ?? null;
            if (! in_array($status, ['matched','catalog_only'], true)) {
                return $blocked('OMS_SNAPSHOT_UNVERIFIED_SKU');
            }
            $available = $row['oms_available'] ?? null;
            if ($status === 'matched') {
                $stockRowCount++;
                if (! is_int($available) || $available < 0
                    || ! is_int($row['oms_total'] ?? null)
                    || ! is_int($row['oms_locked'] ?? null)
                    || $row['oms_locked'] < 0 || $row['oms_total'] !== $available + $row['oms_locked']
                    || ! is_int($row['product_id'] ?? null) || $row['product_id'] < 1) {
                    return $blocked('OMS_SNAPSHOT_STOCK_INVALID');
                }
            } elseif ($available !== null || ($row['product_id'] ?? null) !== null
                && ! is_int($row['product_id'])) {
                return $blocked('OMS_SNAPSHOT_CATALOG_ONLY_INVALID');
            }
            $catalog[] = [
                'sku'=>$sku, 'warehouse_code'=>$warehouseCode,
                'resolution_status'=>$status, 'product_id'=>$row['product_id'] ?? null,
                'available_quantity'=>$status === 'matched' ? $available : null,
            ];
        }
        if ($stockRowCount !== $snapshot['summary']['oms_rows']) {
            return $blocked('OMS_SNAPSHOT_ROW_COUNT_MISMATCH');
        }
        if ($lines === [] || count($lines) > 100 || $liveApprovedOmsSkus === []) {
            return $blocked('OMS_ORDER_SKU_PREFLIGHT_INCOMPLETE');
        }
        // Several lines of the same canonical Product must share the same OMS stock pool.
        $grouped = [];
        foreach ($lines as $line) {
            $id = $line['product_id'] ?? null;
            $sku = trim((string) ($line['canonical_sku'] ?? ''));
            $qty = $line['quantity'] ?? null;
            if (! is_int($id) || $id < 1 || SkuMatchKey::compute($sku) === null
                || ! is_int($qty) || $qty < 1) return $blocked('OMS_ORDER_SKU_INPUT_INVALID');
            if (isset($grouped[$id]) && SkuMatchKey::compute($grouped[$id]['sku']) !== SkuMatchKey::compute($sku)) {
                return $blocked('OMS_ORDER_PRODUCT_IDENTITY_CONFLICT');
            }
            if (! isset($grouped[$id])) $grouped[$id] = ['sku'=>$sku,'qty'=>0];
            $grouped[$id]['qty'] += $qty;
        }
        $resolved = []; $usedOms = [];
        foreach ($grouped as $productId=>$group) {
            $aliases = $approvedAliasesByProduct[$productId] ?? [];
            foreach ($aliases as $alias) {
                if (! is_int($alias['product_id'] ?? null) || $alias['product_id'] !== (int)$productId) {
                    return $blocked('OMS_ALIAS_PRODUCT_MISMATCH');
                }
            }
            $candidates = array_map(static function (array $row) use ($productId, $liveApprovedOmsSkus): array {
                return [
                    'sku'=>$row['sku'], 'warehouse_code'=>$row['warehouse_code'],
                    'approval_status'=>in_array($row['sku'], $liveApprovedOmsSkus, true) ? 2 : 0,
                    'available_quantity'=>$row['available_quantity'],
                    // No guessed physical identity; the inventory reconciliation must
                    // have resolved the exact OMS row to THIS ERP product.
                    'physical_identity_confirmed'=>$row['resolution_status'] === 'matched'
                        && $row['product_id'] === $productId,
                ];
            }, $catalog);
            $choice = $this->selector->select($group['sku'], $aliases,
                $warehouseCode, $group['qty'], $candidates, true);
            if ($choice['status'] !== 'ready') return $blocked($choice['code']);
            $code = $choice['oms_sku'];
            if (isset($usedOms[$code])) return $blocked('OMS_SKU_CROSS_PRODUCT_COLLISION');
            $usedOms[$code] = true;
            $resolved[] = ['product_id'=>(int)$productId,'canonical_sku'=>$group['sku'],
                'oms_sku'=>$code,'quantity'=>$group['qty']];
        }
        return ['status'=>'ready','code'=>'OMS_SNAPSHOT_SKU_UNIQUE_VERIFIED','items'=>$resolved];
    }
}
