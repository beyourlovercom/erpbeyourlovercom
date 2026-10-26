<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Orders\Infrastructure\Models\Order;

final class SingleOrderPreview
{
    public function __construct(private readonly WarehouseSkuResolver $skuResolver) {}

    /** @return array<string,mixed> */
    public function forOrder(Order $order): array
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

        $channel = trim((string) ($shipment?->carrierService?->service_value ?: $shipment?->service ?: ''));
        $add($channel === '', 'native_logistics_channel_missing');
        $add($shipment?->carrierService && ! $shipment->carrierService->is_active, 'logistics_channel_inactive');

        $skuInputs = $order->items->map(
            static fn ($item): string => trim((string) ($item->resolved_fulfillment_sku ?: $item->sku))
        )->values()->all();
        $skuMapping = $this->skuResolver->resolve($shipment?->warehouse_id, $skuInputs);
        foreach ($skuMapping['blockers'] as $blocker) {
            $blocking[] = $blocker;
        }

        $items = [];
        foreach ($order->items as $item) {
            $erpSku = trim((string) ($item->resolved_fulfillment_sku ?: $item->sku));
            $sku = $skuMapping['resolved'][$erpSku] ?? '';
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
            'sku_mapping' => $skuMapping['rows'],
            'payload_hash' => $fingerprint,
            'auto_dispatch' => false,
            'oms_reference' => $thirdOrderNo,
        ];
    }
}
