<?php

namespace App\Modules\Logistics\Application\ShipOut;

use App\Modules\Logistics\Infrastructure\ShipOut\ShipOutProductionInventoryAudit;
use App\Modules\Logistics\Infrastructure\ShipOut\ShipOutProductionReadOnlyGateway;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Application\VerifiedOmsProductIdentityResolver;
use App\Modules\Products\Application\SkuMatchKey;
use App\Modules\Products\Infrastructure\Models\ProductSkuAlias;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Internal R10 Production read-only preflight. No HTTP POST, no inventory
 * mutation, no attempt reservation and no recipient PII in preview().
 */
final class ShipOutProductionCanaryPreflight
{
    private const WAREHOUSE = '1265864414750314497';

    private const ORG = '57893';

    public function __construct(
        private readonly ShipOutOutboundPayloadFactory $factory,
        private readonly VerifiedOmsProductIdentityResolver $identity,
        private readonly ShipOutProductionInventoryAudit $inventory,
        private readonly ShipOutProductionReadOnlyGateway $reader,
    ) {}

    /** @return array{order_id:int,order_no:string,warehouse_id:string,shipping_mode:string,items:list<array{omsSku:string,qty:int}>,payload_hash:string,ready:bool} */
    public function preview(int $orderId, string $shipDate): array
    {
        $result = $this->build($orderId, $shipDate);

        return $result['summary'];
    }

    /** Preview the ordinary, Dede-approved Production V1 order flow. */
    public function previewApproved(int $orderId, string $shipDate): array
    {
        return $this->buildApproved($orderId, $shipDate)['summary'];
    }

    /** Return a guarded Production V1 payload, without exposing PII to web callers. */
    public function buildApproved(int $orderId, string $shipDate): array
    {
        if (! (bool) config('shipout.production_v1_read_enabled', false)) {
            throw new RuntimeException('SHIPOUT_V1_READ_DISABLED');
        }
        $cutover = (int) config('shipout.production_v1_min_order_id', 0);
        if ($cutover < 1 || $orderId < $cutover) {
            throw new RuntimeException('SHIPOUT_V1_OLD_ORDER_EXCLUDED');
        }

        return $this->buildScoped($orderId, $shipDate, false);
    }

    /**
     * For a privileged internal one-shot dispatcher only. The raw payload
     * contains customer address; never expose it via a web controller or log.
     *
     * @return array{payload:array<int,array<string,mixed>>,summary:array<string,mixed>}
     */
    public function build(int $orderId, string $shipDate): array
    {
        return $this->buildScoped($orderId, $shipDate, true);
    }

    /** @return array{payload:array<int,array<string,mixed>>,summary:array<string,mixed>} */
    private function buildScoped(int $orderId, string $shipDate, bool $canary): array
    {
        if (! app()->environment('production')
            || ! (bool) config('shipout.production_read_enabled', false)) {
            throw new RuntimeException('SHIPOUT_R10_PRODUCTION_READ_DISABLED');
        }
        if (trim((string) config('shipout.production_warehouse_id', '')) !== self::WAREHOUSE
            || trim((string) config('shipout.production_client_org_id', '')) !== self::ORG
            || $orderId <= 0
            || ($canary && trim((string) config('shipout.production_canary_order_id', '')) !== (string) $orderId)) {
            throw new RuntimeException('SHIPOUT_R10_PREFLIGHT_IDENTITY_DENIED');
        }

        // A fixed ship date prevents accidental retroactive/far-future
        // warehouse requests; ShipOut's expected local shipping date is US-LA.
        $today = now('America/Los_Angeles')->startOfDay();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $shipDate)) {
            throw new RuntimeException('SHIPOUT_R10_SHIP_DATE_INVALID');
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $shipDate, 'America/Los_Angeles');
        } catch (\Throwable) {
            throw new RuntimeException('SHIPOUT_R10_SHIP_DATE_INVALID');
        }
        if (! $date || $date->format('Y-m-d') !== $shipDate
            || $date->lt($today) || $date->gt($today->copy()->addDays(7))) {
            throw new RuntimeException('SHIPOUT_R10_SHIP_DATE_INVALID');
        }

        $order = Order::query()->with(['shipment.warehouse', 'shipment.overseaReview', 'address', 'items'])
            ->findOrFail($orderId);
        abort_if($order->isOperationallyArchived(), 409, '归档订单不能提交海外仓。');
        if ($order->shipment?->shipment_status !== 'pending') {
            throw new RuntimeException('SHIPOUT_R10_SHIPMENT_NOT_PENDING');
        }

        // Explicit ['9'] is isolated from sandbox_enabled_modes.
        $payload = $this->factory->fromApprovedOrder(
            $order, self::WAREHOUSE, $shipDate, '9', null, ['9']
        );
        $ref = (string) $payload[0]['orderNo'];
        $allowRef = trim((string) config('shipout.production_canary_order_no', ''));
        if ($canary && ($allowRef === '' || ! hash_equals($allowRef, $ref))) {
            throw new RuntimeException('SHIPOUT_R10_ORDER_NOT_ALLOWLISTED');
        }

        try {
            $attemptExists = DB::table('shipout_dispatch_attempts')
                ->where('order_id', $orderId)->orWhere('order_no', $ref)->exists();
        } catch (\Throwable) {
            throw new RuntimeException('SHIPOUT_R10_ATTEMPT_LEDGER_UNAVAILABLE');
        }
        if ($attemptExists) {
            throw new RuntimeException('SHIPOUT_R10_PRIOR_ATTEMPT_BLOCKED');
        }

        $snapshot = $this->inventory->snapshot();
        if (($snapshot['warehouse_id'] ?? null) !== self::WAREHOUSE
            || ($snapshot['client_org_id'] ?? null) !== self::ORG) {
            throw new RuntimeException('SHIPOUT_R10_STOCK_IDENTITY_UNTRUSTED');
        }
        $eligibleByProductId = [];
        foreach ($snapshot['stock_rows'] ?? [] as $row) {
            if (! is_array($row) || ! ($row['in_product_catalog'] ?? false)
                || ($row['sku_alias_status'] ?? '') !== 'verified'
                || (string) ($row['product_status'] ?? '') !== '1'
                || ! is_int($row['available_quantity'] ?? null)
                || $row['available_quantity'] < 1) {
                continue;
            }
            $providerSku = (string) ($row['provider_sku'] ?? '');
            $resolved = $this->identity->resolve($providerSku);
            if (($resolved['status'] ?? '') !== 'resolved') {
                continue;
            }
            $eligibleByProductId[(int) $resolved['product_id']][] = [
                'omsSku' => $providerSku,
                'available' => $row['available_quantity'],
            ];
        }

        $used = [];
        $items = [];
        foreach ($order->items as $item) {
            $id = (int) $item->product_id;
            $erpSku = trim((string) $item->sku);
            $verified = $this->identity->resolve($erpSku);
            if ($id < 1 || ($verified['status'] ?? '') !== 'resolved'
                || (int) ($verified['product_id'] ?? 0) !== $id) {
                throw new RuntimeException('SHIPOUT_R10_ERP_SKU_UNVERIFIED');
            }
            $options = $eligibleByProductId[$id] ?? [];
            // Preserve exact physical SKU priority. Alias substitution requires
            // an exact, Owner-approved ERP=>provider pair plus the existing
            // independent Product OS physical verification and provider stock.
            // Never infer variants from shared Product IDs or arbitrary aliases.
            $exact = array_values(array_filter(
                $options, fn (array $r) => strcasecmp($r['omsSku'], $erpSku) === 0
            ));
            if (count($exact) === 1) {
                $choice = $exact[0];
            } elseif (count($exact) > 1) {
                throw new RuntimeException('SHIPOUT_R10_PHYSICAL_SKU_AMBIGUOUS');
            } else {
                // New products need no new .env allowlist entry. Product360's
                // explicit physical-equivalence approval persists on the alias.
                // Only verified aliases for the same product AND unchanged
                // canonical SKU are eligible; catalog + signed stock are
                // independently checked above. A second candidate blocks.
                $approved = [];
                $candidateKeys = ProductSkuAlias::query()
                    ->where('product_id', $id)
                    ->where('is_active', true)
                    ->whereNotNull('oms_verified_at')
                    ->whereNotNull('oms_dispatch_approved_at')
                    ->where('oms_dispatch_canonical_sku', (string) $verified['canonical_sku'])
                    ->whereNotNull('oms_identity_evidence')
                    ->get()
                    ->filter(fn ($alias) => trim((string) $alias->oms_identity_evidence) !== '')
                    ->pluck('match_key')
                    ->all();
                foreach ($options as $candidate) {
                    $key = SkuMatchKey::compute((string) $candidate['omsSku']);
                    if ($key !== null && in_array($key, $candidateKeys, true)) {
                        $approved[$candidate['omsSku']] = $candidate;
                    }
                }
                // Transitional exact pair for historically approved stock
                // identities; no wildcard or per-product auto-whitelisting.
                foreach (explode(',', (string) config('shipout.production_verified_alias_pairs', '')) as $pair) {
                    $parts = array_map('trim', explode('=>', $pair, 2));
                    if (count($parts) !== 2 || strcasecmp($parts[0], $erpSku) !== 0 || $parts[1] === '') {
                        continue;
                    }
                    foreach ($options as $candidate) {
                        if (strcasecmp($candidate['omsSku'], $parts[1]) === 0) {
                            $approved[$candidate['omsSku']] = $candidate;
                        }
                    }
                }
                if (count($approved) === 1) {
                    $choice = array_values($approved)[0];
                } elseif ($approved === []) {
                    throw new RuntimeException('SHIPOUT_R10_PHYSICAL_SKU_NOT_AVAILABLE');
                } else {
                    throw new RuntimeException('SHIPOUT_R10_PHYSICAL_SKU_AMBIGUOUS');
                }
            }
            $sku = $choice['omsSku'];
            $qty = (int) $item->quantity;
            if ($qty < 1 || isset($used[$sku])) {
                throw new RuntimeException('SHIPOUT_R10_DUPLICATE_OR_INVALID_ITEM');
            }
            if ($choice['available'] < $qty) {
                throw new RuntimeException('SHIPOUT_R10_INSUFFICIENT_AVAILABLE_STOCK');
            }
            $used[$sku] = true;
            $items[] = ['omsSku' => $sku, 'qty' => $qty];
        }
        if ($items === []) {
            throw new RuntimeException('SHIPOUT_R10_NO_ITEMS');
        }

        if (! preg_match('/^[A-Z]{2}$/D', (string) ($payload[0]['toAddress']['stateCode'] ?? ''))
            || ($payload[0]['toAddress']['countryCode'] ?? null) !== 'US') {
            throw new RuntimeException('SHIPOUT_R10_US_ADDRESS_INVALID');
        }

        $payload[0]['shipmentForms'][0]['productList'] = $items;
        $form = $payload[0]['shipmentForms'][0];
        if (count($payload) !== 1 || (int) ($payload[0]['sid'] ?? -1) !== 1
            || count($payload[0]['shipmentForms'] ?? []) !== 1
            || (string) ($form['outboundInfo']['sysServiceId'] ?? '') !== '9'
            || (int) ($form['shippingInfo']['carrierId'] ?? 0) !== 9
            || isset($form['outboundInfo']['serviceGroupId'])
            || isset($form['outboundInfo']['shipmentOutboundLabel'])) {
            throw new RuntimeException('SHIPOUT_R10_MODE_POLICY_BLOCKED');
        }

        // An unavailable/ambiguous provider response always blocks, including
        // a previous async accept which has not yet generated an outbound.
        $existing = $this->reader->orderPage(self::WAREHOUSE, $ref, 1, 10);
        if ($existing['total'] !== 0 || $existing['records'] !== []) {
            throw new RuntimeException('SHIPOUT_R10_PROVIDER_ORDER_EXISTS');
        }

        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return [
            'payload' => $payload,
            'summary' => [
                'ready' => true,
                'order_id' => $orderId,
                'order_no' => $ref,
                'warehouse_id' => self::WAREHOUSE,
                'shipping_mode' => '9',
                'items' => $items,
                'payload_hash' => $hash,
            ],
        ];
    }
}
