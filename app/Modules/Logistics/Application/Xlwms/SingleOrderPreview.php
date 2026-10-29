<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsInventorySnapshot;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Products\Application\VerifiedOmsProductIdentityResolver;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Facades\Schema;

final class SingleOrderPreview
{
    /** @return array<string,mixed> */
    public function forOrder(Order $order, bool $requireFreshSnapshot = true): array
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
        $add(! $shipment || $shipment->warehouse?->code !== 'US-SM', 'wrong_warehouse');
        $add($order->order_status?->value !== 'pending_shipment', 'order_not_pending_shipment');
        $add(! $shipment || $shipment->shipment_status !== 'pending', 'shipment_not_pending');
        $add(! $review || $review->status !== 'approved'
            || (int) $review->reviewed_warehouse_id !== (int) ($shipment?->warehouse_id ?? 0), 'warehouse_review_not_approved');
        $add(filled($shipment?->tracking_number), 'tracking_already_assigned');
        $add(! $connection || ! filled($connection->app_key_encrypted)
            || ! filled($connection->app_secret_encrypted), 'api_credentials_missing');
        $add(! $connection || ! filled($connection->oms_warehouse_code), 'oms_warehouse_not_bound');
        $add(! $address || ! filled($address->recipient_name), 'recipient_missing');
        $add(! $address || ! filled($address->address_line_1), 'address_missing');
        $add(! $address || ! filled($address->city), 'city_missing');
        $add(! $address || ! filled($address->postal_code), 'postal_code_missing');
        $country = strtoupper(trim((string) ($address?->country ?? '')));
        $add(strlen($country) !== 2, 'country_iso2_missing');
        $state = strtoupper(trim((string) ($address?->province_state ?? '')));
        $add(! $state, 'province_missing');
        $add(in_array($country, ['US', 'CA', 'AU'], true) && ! preg_match('/^[A-Z]{2,3}$/', $state), 'province_code_not_confirmed');

        // US-SM Owner-approved 2026-10-29: the OMS provider chooses the
        // lowest-priced available service. Do not pretend the ERP's existing
        // USPS/Priority Mail selection is the resulting last-mile carrier.
        // Only this warehouse is eligible for this fixed native OMS code.
        $channel = $shipment?->warehouse?->code === 'US-SM'
            ? trim((string) config('xlwms.us_sm_oms_logistics_channel', ''))
            : trim((string) ($shipment?->carrierService?->service_value ?: $shipment?->service ?: ''));
        $add($channel === '', 'native_logistics_channel_missing');
        $add($shipment?->carrierService && ! $shipment->carrierService->is_active, 'logistics_channel_inactive');

        $items = [];
        foreach ($order->items as $item) {
            $sku = trim((string) ($item->resolved_fulfillment_sku ?: $item->sku));
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
            if (! $inventory) $blocking[] = 'inventory_unverified:'.$sku;
            elseif ((int) $inventory->quantity_available < $qty) {
                $blocking[] = 'insufficient_warehouse_stock:'.$sku;
            }
            $items[] = ['sku' => $sku, 'quantity' => $qty];
        }
        $add($items === [], 'no_valid_order_items');

        // Current main's Product OS and V2 inventory snapshot are required
        // before an outbound create can ever be reached. This is deliberately
        // local/read-only: it does not refresh OMS or mutate ERP stock.
        foreach ($order->items as $item) {
            $sku = trim((string) ($item->resolved_fulfillment_sku ?: $item->sku));
            $identity = $this->identities->resolve($sku);
            if (($identity['status'] ?? null) !== 'resolved') {
                $blocking[] = (string) ($identity['code'] ?? 'OMS_PRODUCT_IDENTITY_NOT_VERIFIED');
            }
        }
        if ($requireFreshSnapshot) {
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
            'isSubmit' => 1,
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
    ) {}

    /** @param iterable<object> $items @param list<string> $blocking */
    private function addFreshAppliedV2Blockers(?int $warehouseId, iterable $items, array &$blocking): void
    {
        if (! $warehouseId || ! Schema::hasTable('xlwms_inventory_snapshots')) {
            $blocking[] = 'OMS_SNAPSHOT_NOT_RECONCILED';
            return;
        }
        $snapshot = XlwmsInventorySnapshot::query()->where('warehouse_id', $warehouseId)->latest('id')->first();
        $summary = (array) ($snapshot?->summary ?? []);
        $fresh = $snapshot?->fetched_at
            && $snapshot->fetched_at->lte(now())
            && $snapshot->fetched_at->diffInSeconds(now()) <= 120;
        if (! $snapshot || $snapshot->status !== 'applied'
            || ($summary['v2'] ?? false) !== true
            || ($summary['v2_applied'] ?? false) !== true
            || (int) $snapshot->unmatched_sku_count !== 0
            || (int) $snapshot->blocked_sku_count !== 0
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
