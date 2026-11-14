<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Application\VerifiedOmsProductIdentityResolver;
use App\Modules\Products\Infrastructure\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ERP-only last-known provider stock, independent from warehouse_inventory.
 *
 * Real stock is always provider-authoritative. A successful GET persists this
 * reference before the separate ERP main-mirror transaction. No provider POST.
 */
final class WarehouseLastReadStockService
{
    public function __construct(private readonly VerifiedOmsProductIdentityResolver $identity) {}

    /** @param array<string,mixed> $provider */
    public function captureMorePlus(Warehouse $warehouse, array $provider, CarbonImmutable $readAt, string $source = 'api_read'): void
    {
        $this->guard($warehouse);
        $rows = $provider['stock_rows'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || $rows === []) {
            throw new RuntimeException('MOREPLUS_PROVIDER_STOCK_EMPTY_REVIEW_REQUIRED');
        }
        $verified = [];
        $seenSku = [];
        $seenProduct = [];
        $canonicalCounts = array_count_values(array_map('strtolower', array_filter(array_map(
            fn ($v) => ($v['sku_alias_status'] ?? '') === 'verified'
                ? trim((string)($v['canonical_sku'] ?? '')) : '',
            $rows
        ))));
        foreach ($rows as $row) {
            $sku = trim((string)($row['provider_sku'] ?? ''));
            $canonical = trim((string)($row['canonical_sku'] ?? ''));
            $qty = $row['standard_quantity'] ?? null;
            $available = $row['available_quantity'] ?? null;
            if ($sku === '' || isset($seenSku[strtolower($sku)])) {
                throw new RuntimeException('LAST_READ_PROVIDER_DUPLICATE_SKU');
            }
            $seenSku[strtolower($sku)] = true;
            if (! is_int($qty) || $qty < 0 || ! is_int($available) || $available < 0 || $available > $qty) {
                throw new RuntimeException('LAST_READ_PROVIDER_UNTRUSTED_QUANTITY');
            }
            if (($row['sku_alias_status'] ?? '') !== 'verified' || $canonical === ''
                || ($canonicalCounts[strtolower($canonical)] ?? 0) !== 1) {
                // Persist raw supplier quantities; never assign a duplicate
                // or unverified identity to an ERP product.
                $verified[] = ['provider_sku' => $sku, 'product_id' => null, 'qty' => $qty, 'status' => 'sku_unverified'];
                continue;
            }
            $product = Product::query()->where('sku', $canonical)->first();
            if (! $product || isset($seenProduct[(int)$product->id])) {
                $verified[] = ['provider_sku' => $sku, 'product_id' => null, 'qty' => $qty, 'status' => 'sku_unverified'];
                continue;
            }
            $seenProduct[(int)$product->id] = true;
            $verified[] = ['provider_sku' => $sku, 'product_id' => (int)$product->id, 'qty' => $qty, 'status' => 'read_success'];
        }
        // Only when the entire signed stock list has been identity-verified
        // may locally absent SKU rows get an explicit zero reference.
        if (count($seenProduct) === count($rows)) {
            $erpRows = WarehouseInventory::query()->where('warehouse_id', $warehouse->id)
                ->with('product')->get();
            foreach ($erpRows as $inventory) {
                if (isset($seenProduct[(int)$inventory->product_id])) {
                    continue;
                }
                $verified[] = ['provider_sku' => (string)$inventory->product->sku,
                    'product_id' => (int)$inventory->product_id, 'qty' => 0, 'status' => 'read_success'];
            }
        }
        $snapshot = hash('sha256', json_encode([
            'warehouse_id' => (int)$warehouse->id,
            'read_at' => $readAt->toIso8601String(),
            'rows' => $verified,
        ], JSON_THROW_ON_ERROR));

        DB::transaction(function () use ($warehouse, $verified, $readAt, $source, $snapshot): void {
            Warehouse::query()->whereKey($warehouse->id)->lockForUpdate()->firstOrFail();
            foreach ($verified as $record) {
                $query = DB::table('warehouse_stock_read_references')
                    ->where('warehouse_id', $warehouse->id)
                    ->where('provider_sku', $record['provider_sku']);
                $existing = (clone $query)->lockForUpdate()->first();
                // Shipment evidence whose actual outbound time falls after the
                // provider snapshot must remain subtracted even when it arrives
                // while this GET was in flight.
                $adjusted = $record['product_id'] === null ? 0
                    : (int) DB::table('warehouse_stock_read_adjustments')
                        ->where('warehouse_id', $warehouse->id)
                        ->where('product_id', $record['product_id'])
                        ->where('outcome', 'applied')
                        ->where('shipped_at_utc', '>', $readAt->toDateTimeString())
                        ->sum('quantity');
                $raw = (int)$record['qty'];
                $safeAdjusted = min($adjusted, $raw);
                $values = [
                    'product_id' => $record['product_id'],
                    'last_read_raw_qty' => $raw,
                    'last_read_stock_qty' => $raw - $safeAdjusted,
                    'post_read_adjustment_qty' => $safeAdjusted,
                    'last_read_stock_at' => $readAt->toDateTimeString(),
                    'last_read_stock_source' => $source,
                    'last_read_stock_status' => $record['status'],
                    'last_read_error_code' => $adjusted > $raw ? 'SHIPMENT_EXCEEDS_SNAPSHOT_REVIEW' : null,
                    'snapshot_id' => $snapshot,
                    'updated_at' => now(),
                ];
                if ($existing && $existing->last_read_stock_at !== null
                    && CarbonImmutable::parse($existing->last_read_stock_at)->gt($readAt)) {
                    continue;
                }
                if ($existing) {
                    $query->update($values);
                } else {
                    DB::table('warehouse_stock_read_references')->insert([
                        'warehouse_id' => $warehouse->id,
                        'provider_sku' => $record['provider_sku'],
                        'created_at' => now(),
                        ...$values,
                    ]);
                }
                DB::table('warehouse_stock_read_events')->insert([
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $record['product_id'],
                    'provider_sku' => $record['provider_sku'],
                    'event_type' => 'source_read',
                    'status' => $record['status'],
                    'raw_quantity' => $raw,
                    'reference_quantity' => $raw - $safeAdjusted,
                    'source' => $source,
                    'snapshot_id' => $snapshot,
                    'occurred_at' => $readAt->toDateTimeString(),
                    'created_at' => now(),
                ]);
            }
        });
    }

    /** API read errors do not erase raw, net quantity, timestamp or source. */
    public function markReadFailure(Warehouse $warehouse, string $code = 'PROVIDER_READ_FAILED'): void
    {
        $this->guard($warehouse);
        DB::transaction(function () use ($warehouse, $code): void {
            Warehouse::query()->whereKey($warehouse->id)->lockForUpdate()->firstOrFail();
            DB::table('warehouse_stock_read_references')->where('warehouse_id', $warehouse->id)->update([
                'last_read_stock_status' => 'read_failed_last_known',
                'last_read_error_code' => substr($code, 0, 80),
                'last_read_failed_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('warehouse_stock_read_events')->insert([
                'warehouse_id' => $warehouse->id,
                'event_type' => 'source_read_failed', 'status' => 'read_failed_last_known',
                'source' => 'api_read', 'occurred_at' => now(), 'created_at' => now(),
            ]);
        });
    }

    public function markApplyResult(Warehouse $warehouse, CarbonImmutable $readAt, bool $ok): void
    {
        $this->guard($warehouse);
        // Never mark a newer read as failed because an older sync finished later.
        DB::transaction(function () use ($warehouse, $readAt, $ok): void {
            Warehouse::query()->whereKey($warehouse->id)->lockForUpdate()->firstOrFail();
            $query = DB::table('warehouse_stock_read_references')
                ->where('warehouse_id', $warehouse->id)
                ->where('last_read_stock_at', $readAt->toDateTimeString())
                ->whereIn('last_read_stock_status', ['read_success', 'read_success_apply_failed', 'synced']);
            $affected = $query->update([
                'last_read_stock_status' => $ok ? 'synced' : 'read_success_apply_failed',
                'updated_at' => now(),
            ]);
            if ($affected > 0) {
                DB::table('warehouse_stock_read_events')->insert([
                    'warehouse_id' => $warehouse->id, 'event_type' => 'erp_mirror_result',
                    'status' => $ok ? 'synced' : 'read_success_apply_failed',
                    'source' => 'sync', 'occurred_at' => now(), 'created_at' => now(),
                ]);
            }
        });
    }

    /**
     * Record shipment only after exact signed OMS shipped proof, never on
     * allocation, tracking entry, carrier creation or a manual shipment guess.
     * The proof line mapping is checked before applying any SKU adjustment.
     * @return array{applied:int,skipped:int,review:int}
     */
    public function recordVerifiedMorePlusOutbound(
        Order $order, string $providerOrderId, string $outboundReference, string $verifiedShippedAtUtc
    ): array {
        $order->loadMissing(['shipment.warehouse', 'items']);
        $warehouse = $order->shipment?->warehouse;
        if (! $warehouse) {
            throw new RuntimeException('LAST_READ_SHIPMENT_WAREHOUSE_MISSING');
        }
        $this->guard($warehouse);
        if (trim($providerOrderId) === '' || trim($outboundReference) === '') {
            throw new RuntimeException('LAST_READ_OUTBOUND_PROOF_IDENTITY_REQUIRED');
        }
        $shippedAt = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $verifiedShippedAtUtc, 'UTC');
        if (! $shippedAt || $shippedAt->format('Y-m-d H:i:s') !== $verifiedShippedAtUtc) {
            throw new RuntimeException('LAST_READ_OUTBOUND_TIME_INVALID');
        }
        $items = [];
        foreach ($order->items as $item) {
            $sku = trim((string)$item->sku);
            $id = $this->identity->resolve($sku);
            if (($id['status'] ?? '') !== 'resolved'
                || (int)($id['product_id'] ?? 0) !== (int)$item->product_id
                || (int)$item->quantity <= 0) {
                throw new RuntimeException('LAST_READ_OUTBOUND_SKU_IDENTITY_REVIEW');
            }
            $productId = (int)$item->product_id;
            if (isset($items[$productId])) {
                // One order may have duplicate line items; aggregate by real product.
                $items[$productId]['quantity'] += (int)$item->quantity;
            } else {
                $items[$productId] = ['product_id'=>$productId,'provider_sku'=>$sku,'quantity'=>(int)$item->quantity];
            }
        }
        if ($items === []) {
            throw new RuntimeException('LAST_READ_OUTBOUND_NO_ITEMS');
        }
        return $this->recordVerifiedLines($warehouse, $items, $providerOrderId, $outboundReference, $shippedAt);
    }

    /** @param array<int,array{product_id:int,provider_sku:string,quantity:int}> $items
     * @return array{applied:int,skipped:int,review:int}
     */
    public function recordVerifiedLines(
        Warehouse $warehouse, array $items, string $providerOrderId,
        string $outboundReference, CarbonImmutable $shippedAt
    ): array {
        $this->guard($warehouse);
        $result = ['applied'=>0, 'skipped'=>0, 'review'=>0];
        DB::transaction(function () use ($warehouse, $items, $providerOrderId, $outboundReference, $shippedAt, &$result): void {
            Warehouse::query()->whereKey($warehouse->id)->lockForUpdate()->firstOrFail();
            foreach ($items as $line) {
                $productId = (int)$line['product_id'];
                $qty = (int)$line['quantity'];
                if ($productId <= 0 || $qty <= 0 || $qty > 1000000) {
                    throw new RuntimeException('LAST_READ_OUTBOUND_INVALID_LINE');
                }
                $hash = hash('sha256', $providerOrderId.'|'.$outboundReference.'|'.(string)$productId);
                if (DB::table('warehouse_stock_read_adjustments')->where('warehouse_id', $warehouse->id)
                    ->where('product_id', $productId)->where('event_hash', $hash)->exists()) {
                    $result['skipped']++;
                    continue;
                }
                $ref = DB::table('warehouse_stock_read_references')
                    ->where('warehouse_id', $warehouse->id)
                    ->where('product_id', $productId)->orderByDesc('last_read_stock_at')
                    ->orderByDesc('id')->lockForUpdate()->first();
                $outcome = 'no_baseline';
                $snapshotAt = null;
                if ($ref && $ref->last_read_stock_at !== null) {
                    $snapshotAt = CarbonImmutable::parse($ref->last_read_stock_at, 'UTC');
                    if ($shippedAt->lessThanOrEqualTo($snapshotAt)) {
                        $outcome = 'needs_review_snapshot_boundary';
                    } elseif ($ref->last_read_stock_qty === null || $qty > (int)$ref->last_read_stock_qty) {
                        $outcome = 'needs_review';
                    } else {
                        $outcome = 'applied';
                        DB::table('warehouse_stock_read_references')->where('id',$ref->id)->update([
                            'last_read_stock_qty' => (int)$ref->last_read_stock_qty - $qty,
                            'post_read_adjustment_qty' => (int)$ref->post_read_adjustment_qty + $qty,
                            'updated_at' => now(),
                        ]);
                        $result['applied']++;
                    }
                }
                DB::table('warehouse_stock_read_adjustments')->insert([
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $productId,
                    'provider_sku' => $line['provider_sku'],
                    'event_hash' => $hash,
                    'quantity' => $qty,
                    'shipped_at_utc' => $shippedAt->toDateTimeString(),
                    'outcome' => $outcome,
                    'applied_at' => $outcome === 'applied' ? now() : null,
                    'snapshot_at' => $snapshotAt?->toDateTimeString(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                if (str_starts_with($outcome, 'needs_review')) $result['review']++;
                if ($outcome === 'no_baseline') $result['skipped']++;
            }
        });
        return $result;
    }

    /** @return array<int,object> */
    public function forWarehouse(Warehouse $warehouse): array
    {
        if (strtoupper((string)$warehouse->code) !== 'US-LA') return [];
        $reviews = DB::table('warehouse_stock_read_adjustments')
            ->where('warehouse_id', $warehouse->id)
            ->where('outcome', 'like', 'needs_review%')
            ->selectRaw('product_id, COUNT(*) as pending_count')
            ->groupBy('product_id')->pluck('pending_count', 'product_id');
        return DB::table('warehouse_stock_read_references')
            ->where('warehouse_id',$warehouse->id)->whereNotNull('product_id')
            ->orderByDesc('last_read_stock_at')->orderByDesc('id')->get()
            ->unique('product_id')->keyBy('product_id')->map(function ($ref) use ($reviews) {
                $ref->review_count = (int)($reviews[$ref->product_id] ?? 0);
                return $ref;
            })->all();
    }

    private function guard(Warehouse $warehouse): void
    {
        if (strtoupper((string)$warehouse->code) !== 'US-LA') {
            throw new RuntimeException('LAST_READ_MOREPLUS_ONLY');
        }
    }
}
