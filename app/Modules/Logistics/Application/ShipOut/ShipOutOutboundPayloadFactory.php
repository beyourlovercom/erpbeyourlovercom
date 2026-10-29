<?php

namespace App\Modules\Logistics\Application\ShipOut;

use App\Modules\Logistics\Application\OverseaFiles\WarehouseOrderReference;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Application\VerifiedOmsProductIdentityResolver;
use RuntimeException;

/**
 * Pure DTO from an existing Dede-approved, US-LA ERP order. Not a dispatch action.
 * Reuses authoritative ERP OrderAddress, OrderItem, Product OS alias and warehouse identity.
 */
final class ShipOutOutboundPayloadFactory
{
    public function __construct(
        private readonly WarehouseOrderReference $references,
        private readonly VerifiedOmsProductIdentityResolver $identity,
    ) {}

    /** @return array<string,mixed> */
    public function fromApprovedOrder(
        Order $order, string $providerWarehouseId, string $shipDate,
        string $mode = '9'
    ): array
    {
        $order->loadMissing(['shipment.warehouse', 'shipment.overseaReview', 'address', 'items']);
        $shipment = $order->shipment;
        $review = $shipment?->overseaReview;

        if ($order->order_status?->value !== 'pending_shipment'
            || $shipment?->warehouse?->code !== 'US-LA'
            || $review?->status !== 'approved'
            || (int) $review->reviewed_warehouse_id !== (int) $shipment->warehouse_id
            || ! $review->reviewed_by || ! $review->reviewed_at
            || $shipment->carrier !== 'USPS'
            || trim((string) ($shipment->tracking_number ?? '')) !== ''
            || trim($providerWarehouseId) === '') {
            throw new RuntimeException('SHIPOUT_ERP_APPROVAL_OR_WAREHOUSE_BLOCKED');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $shipDate)) {
            throw new RuntimeException('SHIPOUT_SHIP_DATE_INVALID');
        }
        $a = $order->address;
        if (! $a || strtoupper(trim((string) $a->country)) !== 'US') {
            throw new RuntimeException('SHIPOUT_US_ONLY_FIRST_RELEASE');
        }
        $map = [
            'name' => $a->recipient_name, 'phone' => $a->phone,
            'addressLine1' => $a->address_line_1, 'city' => $a->city,
            'stateCode' => $a->province_state, 'zipCode' => $a->postal_code,
        ];
        foreach ($map as $field => $value) {
            if (trim((string) $value) === '') {
                throw new RuntimeException('SHIPOUT_RECIPIENT_MISSING_'.$field);
            }
        }
        $items = $order->items;
        if ($items->isEmpty()) {
            throw new RuntimeException('SHIPOUT_NO_ITEMS');
        }
        $seen = [];
        $productList = [];
        foreach ($items as $item) {
            $sku = trim((string) $item->sku);
            $qty = (int) $item->quantity;
            if ($sku === '' || $qty <= 0 || ! $item->product_id) {
                throw new RuntimeException('SHIPOUT_ITEM_INVALID');
            }
            $identity = $this->identity->resolve($sku);
            if (($identity['status'] ?? null) !== 'resolved'
                || (int) ($identity['product_id'] ?? 0) !== (int) $item->product_id) {
                throw new RuntimeException('SHIPOUT_SKU_NOT_VERIFIED');
            }
            if (isset($seen[$sku])) {
                throw new RuntimeException('SHIPOUT_DUPLICATE_SKU_REQUIRES_AGGREGATION');
            }
            $seen[$sku] = true;
            $productList[] = ['omsSku' => $sku, 'qty' => $qty];
        }
        $reference = trim($this->references->warehouse($order));
        if ($reference === '' || mb_strlen($reference) > 60) {
            throw new RuntimeException('SHIPOUT_ORDER_REFERENCE_INVALID');
        }

        $allowedModes = array_map('trim', explode(',', (string) config('shipout.sandbox_enabled_modes', '9')));
        if (! in_array($mode, $allowedModes, true) || ! in_array($mode, ['9', '10'], true)) {
            throw new RuntimeException('SHIPOUT_SHIPPING_MODE_NOT_ENABLED');
        }
        $group = trim((string) config('shipout.sandbox_service_group_id', ''));
        if ($mode === '10' && preg_match('/^[1-9][0-9]{8,24}$/', $group) !== 1) {
            throw new RuntimeException('SHIPOUT_SERVICE_GROUP_NOT_VERIFIED');
        }
        // sysServiceId=10 represents selecting an existing warehouse channel.
        // Its serviceGroupId must come from a verified, bound warehouse lookup,
        // not arbitrary browser input or the carrierId from mode 9.
        $outboundInfo = $mode === '10'
            ? ['sysServiceId' => '10', 'serviceGroupId' => $group]
            : ['sysServiceId' => '9'];

        return [[
            'sid' => 1,
            'orderNo' => $reference,
            'warehouseId' => $providerWarehouseId,
            'orderSummary' => ['orderDate' => $order->order_date?->format('Y-m-d 00:00:00') ?? now()->format('Y-m-d H:i:s')],
            'toAddress' => [
                'name' => trim((string) $a->recipient_name),
                'phone' => trim((string) $a->phone),
                'email' => trim((string) $a->email),
                'company' => trim((string) $a->company),
                'addressLine1' => trim((string) $a->address_line_1),
                'addressLine2' => trim((string) $a->address_line_2),
                'city' => trim((string) $a->city),
                'stateCode' => strtoupper(trim((string) $a->province_state)),
                'zipCode' => trim((string) $a->postal_code),
                'countryCode' => 'US',
            ],
            'shipmentForms' => [[
                'shippingInfo' => [
                    // sysServiceId=9 (warehouse selects service) requires
                    // carrierId=9. Verified with real ShipOut Sandbox ERR.
                    'shipmentSid' => '1', 'carrierId' => $mode === '10' ? 1 : 9,
                    'shipDate' => $shipDate.' 00:00:00', 'signatureType' => 4,
                ],
                'outboundInfo' => $outboundInfo,
                'productList' => $productList,
            ]],
        ]];
    }
}
