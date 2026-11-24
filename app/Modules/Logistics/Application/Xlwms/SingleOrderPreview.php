<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsInventorySnapshot;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Products\Application\VerifiedOmsProductIdentityResolver;
use App\Modules\Logistics\Application\WarehouseFinalSkuResolver;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Facades\Schema;

final class SingleOrderPreview
{
    /** @return array<string,mixed> */
    public function forOrder(Order $order, bool $requireFreshSnapshot = true, bool $webDraft = false, string $warehouseCode = 'US-SM'): array
    {
        $order->loadMissing(['address', 'items', 'shipment.warehouse', 'shipment.carrierService', 'shipment.overseaReview']);
        $shipment = $order->shipment;
        $review = $shipment?->overseaReview;
        $address = $order->address;
        $connection = $shipment?->warehouse_id
            ? WarehouseOpenApiConnection::query()->where('warehouse_id', $shipment->warehouse_id)->where('provider', 'xlwms')->first()
            : null;
        $blocking = [];
        $add = static function (bool $condition, string $reason) use (&$blocking): void {
            if ($condition) $blocking[] = $reason;
        };
        $add(! $shipment || $shipment->warehouse?->code !== $warehouseCode, 'wrong_warehouse');
        $add(! $webDraft && $order->order_status?->value !== 'pending_shipment', 'order_not_pending_shipment');
        $add(! $webDraft && (! $shipment || $shipment->shipment_status !== 'pending'), 'shipment_not_pending');
        $add(! $webDraft && (! $review || $review->status !== 'approved'
            || (int) $review->reviewed_warehouse_id !== (int) ($shipment?->warehouse_id ?? 0)), 'warehouse_review_not_approved');
        $add(! $webDraft && filled($shipment?->tracking_number), 'tracking_already_assigned');
        $add(! $webDraft && (! $connection || ! filled($connection->app_key_encrypted) || ! filled($connection->app_secret_encrypted)), 'api_credentials_missing');
        $add(! $connection || ! filled($connection->oms_warehouse_code), 'oms_warehouse_not_bound');
        $add($warehouseCode === 'DE' && $connection?->oms_warehouse_code !== 'Hannover', 'DE_OMS_WAREHOUSE_MISMATCH');
        $add(! $address || ! filled($address->recipient_name), 'recipient_missing');
        $add(! $address || ! filled($address->address_line_1), 'address_missing');
        $add(! $address || ! filled($address->city), 'city_missing');
        $add(! $address || ! filled($address->postal_code), 'postal_code_missing');
        $country = (\App\Support\Countries\CountryStandard::resolve($address?->country) ?? '');
        $add(strlen($country) !== 2, 'country_iso2_missing');
        $state = strtoupper(trim((string) ($address?->province_state ?? '')));
        $add(! $state && $warehouseCode !== 'DE', 'province_missing');
        $add(in_array($country, ['US', 'CA', 'AU'], true) && ! preg_match('/^[A-Z]{2,3}$/', $state), 'province_code_not_confirmed');

        // Owner 2026-10-29: US-SM needs NO shipping service / NO label.
        // Always create a DRAFT; a draft is not a warehouse-received outbound.
        $usSm = $shipment?->warehouse?->code === 'US-SM';
        $channel = $usSm
            ? trim((string) ($webDraft ? 'Upload_Shipping_Label' : config('xlwms.us_sm_oms_logistics_channel', '')))
            : trim((string) ($shipment?->carrierService?->service_value ?: $shipment?->service ?: ''));
        // DE-only translation: retain ERP's carrier/service labels and use the
        // exact native code observed in Hannover's signed channel catalogue.
        // The independent verified-code gate still controls actual dispatch.
        if ($warehouseCode === 'DE' && $shipment?->warehouse?->code === 'DE'
            && strcasecmp(trim((string) $shipment?->carrier), 'DHL') === 0) {
            $channel = match ($channel) {
                'DHL PAKET International' => 'DHL PAKET International N',
                default => $channel,
            };
        }
        $add($channel === '', 'native_logistics_channel_missing');
        // R3 DE catalog channels are provider IDs, not the ERP carrier label.
        // Never fall back to US-SM's special service or an unverified DHL alias.
        if ($warehouseCode === 'DE') {
            $add(! in_array($channel, (array) config('xlwms.de_verified_channel_codes', []), true),
                'DE_OMS_CHANNEL_NOT_VERIFIED');
        }
        $add(! $webDraft && $usSm && (bool) config('xlwms.us_sm_require_no_shipping_service', true)
            && $channel !== 'No_Shipping_Service',
            'US_SM_NO_SHIPPING_CHANNEL_REQUIRED');
        $add(! $webDraft && $shipment?->carrierService && ! $shipment->carrierService->is_active, 'logistics_channel_inactive');

        $items = [];
        $skuByItemId = [];
        foreach ($order->items as $item) {
            try {
                $sku = $shipment ? $this->finalSkus->forShipment($shipment, $item) : '';
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $blocking[] = 'final_sku_not_verified';
                continue;
            }
            $qty = (int) $item->quantity;
            if ($sku === '' || $qty <= 0) {
                $blocking[] = 'invalid_item';
                continue;
            }
            // Local ERP inventory preflight only. OMS physical-stock
            // availability must still be confirmed before real dispatch.
            $inventory = $shipment?->warehouse_id && $item->product_id
                ? WarehouseInventory::query()
                    ->where('warehouse_id', $shipment->warehouse_id)
                    ->where('product_id', $item->product_id)->first()
                : null;
            if (! $webDraft && ! $inventory) $blocking[] = 'inventory_unverified:'.$sku;
            elseif (! $webDraft && $inventory && (int) $inventory->quantity_available < $qty) {
                $blocking[] = 'insufficient_warehouse_stock:'.$sku;
            }
            $items[] = ['sku' => $sku, 'quantity' => $qty];
            $skuByItemId[(int) $item->id] = $sku;
        }
        $add($items === [], 'no_valid_order_items');

        // Current main's Product OS and V2 inventory snapshot are required
        // before an outbound create can ever be reached. This is deliberately
        // local/read-only: it does not refresh OMS or mutate ERP stock.
        foreach ($webDraft ? [] : $order->items as $item) {
            $sku = $skuByItemId[(int) $item->id] ?? null;
            if ($sku === null) {
                continue;
            }
            $identity = $this->identities->resolve($sku);
            $mapped = $this->finalSkus->mapped((int) $item->product_id, (int) $shipment?->warehouse_id);
            $verifiedByMapping = $mapped !== null && strcasecmp($mapped, $sku) === 0;
            if (! $verifiedByMapping && (($identity['status'] ?? null) !== 'resolved'
                || (int) ($identity['product_id'] ?? 0) !== (int) $item->product_id)) {
                $blocking[] = (string) ($identity['code'] ?? 'OMS_PRODUCT_IDENTITY_NOT_VERIFIED');
            }
        }
        if ($requireFreshSnapshot && ! $webDraft) {
            $this->addFreshAppliedV2Blockers($shipment?->warehouse_id, $order->items, $blocking);
        }

        // Deterministic and stable; no random key that could permit duplicate retry.
        $thirdOrderNo = 'BYL-ERP-'.(int) $order->id;
        $payload = [
            'whCode' => (string) ($connection?->oms_warehouse_code ?? ''),
            'salesPlatform' => in_array($order->source_type?->value, ['woocommerce', 'website'], true)
                ? 'WOO_COMMERCE' : 'OTHER',
            'referOrderNo' => substr((string) ($order->erp_order_id ?: $order->id), 0, 100),
            'platformOrderNo' => substr((string) ($order->external_order_number ?: $order->erp_order_id ?: $order->id), 0, 50),
            'thirdOrderNo' => $thirdOrderNo,
            'subOrderType' => 1,
            'isSubmit' => $webDraft ? 0 : ($usSm && (bool)config('xlwms.us_sm_create_draft_only', true) ? 0 : 1),
            'logisticsChannel' => $channel,
            'receiver' => trim((string) ($address?->recipient_name ?? '')),
            'telephone' => trim((string) ($address?->phone ?? '')),
            'email' => trim((string) ($address?->email ?? '')),
            'countryRegionCode' => $country,
            'provinceCode' => $state,
            'provinceName' => trim((string) ($address?->province_state ?? '')),
            'cityName' => trim((string) ($address?->city ?? '')),
            'postCode' => trim((string) ($address?->postal_code ?? '')),
            'addressOne' => trim((string) ($address?->address_line_1 ?? '')),
            'addressTwo' => trim((string) ($address?->address_line_2 ?? '')),
            'productList' => $items,
        ];
        $fingerprint = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return [
            'order_id' => (int) $order->id,
            'erp_order_id' => $order->erp_order_id,
            'warehouse' => $shipment?->warehouse?->code,
            'remote_bound' => filled($connection?->oms_warehouse_code),
            'blockers' => array_values(array_unique($blocking)),
            'ready_for_remote_check' => $blocking === [],
            'payload' => $payload,
            'payload_hash' => $fingerprint,
            'auto_dispatch' => false,
            'oms_reference' => $thirdOrderNo,
        ];
    }

    public function __construct(
        private readonly VerifiedOmsProductIdentityResolver $identities,
        private readonly XlwmsInventoryCoverage $coverage,
        private readonly WarehouseFinalSkuResolver $finalSkus,
    ) {}

    /** @param iterable<object> $items @param list<string> $blocking */
    private function addFreshAppliedV2Blockers(?int $warehouseId, iterable $items, array &$blocking): void
    {
        if (! $warehouseId || ! Schema::hasTable('xlwms_inventory_snapshots')) {
            $blocking[] = 'OMS_SNAPSHOT_NOT_RECONCILED';
            return;
        }
        // A checksum-stable OMS refresh reuses an applied snapshot row, so its ID
        // can be older than a read-only candidate. Use the actual observation
        // timestamp, breaking ties by ID; the status/V2 gates below stay strict.
        $snapshot = XlwmsInventorySnapshot::query()
            ->where('warehouse_id', $warehouseId)
            ->orderByDesc('fetched_at')
            ->orderByDesc('id')
            ->first();
        $summary = (array) ($snapshot?->summary ?? []);
        $fresh = $snapshot?->fetched_at
            && $snapshot->fetched_at->lte(now())
            && $snapshot->fetched_at->diffInSeconds(now()) <= 120;
        $isolated = (bool) config('inventory_sku_gate.enabled', false);
        // DE Owner's physical OMS stock authority is independent of historical
        // FIFO/aging. The stock service gives a separate proof after an audited
        // physical-only write, never a falsely marked V2 lot reconciliation.
        $isDePhysical = $snapshot?->wh_code === 'Hannover'
            && ($summary['de_authoritative_physical_v1'] ?? false) === true;
        $validStatus = $isDePhysical || ($isolated
            ? in_array($snapshot?->status, ['applied', 'partial_applied'], true)
            : $snapshot?->status === 'applied');
        if (! $snapshot || ! $validStatus
            || ($snapshot->status === 'partial_applied'
                && ($summary['sku_isolation_write_proof'] ?? false) !== true)
            || ($summary['v2'] ?? false) !== true
            || (! $isDePhysical && ($summary['v2_applied'] ?? false) !== true)
            || (! $isolated && ((int) $snapshot->unmatched_sku_count !== 0
                || (int) $snapshot->blocked_sku_count !== 0))
            || $snapshot->rows()->count() !== (int) $snapshot->provider_sku_count
            || ! $fresh) {
            $blocking[] = ! $fresh ? 'OMS_SNAPSHOT_NOT_FRESH' : 'OMS_SNAPSHOT_NOT_RECONCILED';
            return;
        }
        $available = $this->coverage->providerAvailableByProductId($warehouseId);
        if ($available === []) {
            $blocking[] = 'OMS_SNAPSHOT_STOCK_INVALID';
            return;
        }
        foreach ($items as $item) {
            $productId = (int) ($item->product_id ?? 0);
            $quantity = (int) ($item->quantity ?? 0);
            if ($productId < 1 || $quantity < 1 || ($available[$productId] ?? -1) < $quantity) {
                $blocking[] = 'OMS_SNAPSHOT_STOCK_INSUFFICIENT';
            }
        }
    }
}
