<?php

namespace App\Modules\Sync\Application;

use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\Contracts\WooCommerceTrackingGateway;
use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;

final class WooCommerceManualTrackingOverrideService
{
    public function __construct(
        private readonly WooCommerceTrackingGateway $gateway,
        private readonly ManualSyncService $manualSync,
    ) {}

    /**
     * The explicit "同步官网物流" operator action, not the automatic
     * shipment hook. Tracks a child on the real Woo root and forces
     * Woo Completed after separately verifying the package and status.
     * No SKU guesses, no invented remote orders, no bulk legacy retries.
     *
     * @return array<string,mixed>
     */
    public function pushOne(Order $order, ?Authenticatable $actor): array
    {
        $order->loadMissing(['shipment', 'items', 'fulfillmentParent.externalReferences', 'externalReferences']);
        $shipment = $order->shipment;
        $number = trim((string) $shipment?->tracking_number);
        if ($number === '') {
            throw new DomainException('没有填写真实 Tracking，不能同步官网。');
        }

        $root = $order->fulfillment_parent_id ? $order->fulfillmentParent : $order;
        if (! $root) {
            throw new DomainException('找不到分仓子订单对应的母订单。');
        }
        $source = $root->source_type instanceof \BackedEnum
            ? $root->source_type->value : (string) $root->source_type;
        if ($source !== 'woocommerce') {
            throw new DomainException('订单没有对应的 WooCommerce 官网母订单，无法同步 AfterShip。');
        }

        $descriptor = $this->gateway->descriptor();
        if (! ($descriptor['configured'] ?? false)) {
            throw new DomainException('官网 WooCommerce 同步连接尚未配置。');
        }
        $connection = (string) ($descriptor['connection_key'] ?? '');
        $root->loadMissing('externalReferences');
        $reference = $root->externalReferences->first(
            fn ($item): bool => $item->provider === 'woocommerce' && $item->connection_key === $connection
        );
        if (! $reference || ! ctype_digit((string) $reference->reference)) {
            throw new DomainException('真实官网母订单缺少可靠的 WooCommerce 订单引用。');
        }

        $providerId = (int) $reference->reference;
        $slug = $this->carrierSlug((string) $shipment->carrier);
        if ($slug === 'other') {
            // Keep an already published provider for this exact Tracking.
            // Otherwise, a missing or unknown ERP carrier uses order-level
            // "other" rather than inventing an AfterShip provider.
            $remote = $this->gateway->inspectTracking($providerId);
            if (($remote['tracking_number'] ?? '') === $number && filled($remote['provider'] ?? null)) {
                $slug = (string) $remote['provider'];
            }
        }

        $shipDate = ($shipment->shipped_at ?? $shipment->created_at ?? now())
            ->utc()->format('Y-m-d');

        // A/B/C packages always bind to their actual Woo mother order, not
        // guessed Woo line IDs. Historical root orders missing identity also
        // use an order-level package without altering existing line bindings.
        $lineItems = [];
        if (! $order->fulfillment_parent_id && $order->items->isNotEmpty()
            && $order->items->every(fn ($item): bool => (int) $item->woocommerce_line_item_id > 0 && (int) $item->quantity > 0
            )) {
            $lineItems = $order->items->map(fn ($item): array => [
                'id' => (int) $item->woocommerce_line_item_id,
                'quantity' => (int) $item->quantity,
            ])->all();
            if (count(array_unique(array_column($lineItems, 'id'))) !== count($lineItems)) {
                $lineItems = [];
            }
        }

        // Only this explicit, permission-scoped manual action can override
        // protected Woo statuses. Auto dispatch stays fail-closed.
        $beforeStatus = $this->gateway->inspectOrderStatus($providerId);
        if (in_array($beforeStatus, ['cancelled', 'refunded', 'failed', 'on-hold', 'trash'], true)) {
            Log::warning('tracking.manual_override.protected_woo_status', [
                'root_order_id' => (int) $root->getKey(),
                'fulfillment_order_id' => (int) $order->getKey(),
                'previous_woo_status' => $beforeStatus,
                'actor_id' => $actor?->getAuthIdentifier(),
            ]);
        }
        $tracking = $this->gateway->syncTracking(
            $providerId, $slug, $number, $shipDate, $lineItems, allowProtectedStatus: true
        );
        if (! ($tracking['verified'] ?? false)) {
            throw new \RuntimeException('官网 AfterShip Tracking 写入后回读未通过。');
        }

        $status = $this->gateway->syncOrderStatus($providerId, 'completed', allowProtectedStatus: true);
        if (! ($status['verified'] ?? false) || ($status['status'] ?? '') !== 'completed') {
            throw new \RuntimeException('官网母订单 Completed 状态回读未通过，物流可能已经写入，请复查。');
        }

        // Remember the explicit owner-approved override: a subsequent
        // partial-shipment automatic status projection must not undo it.
        $root->operationalMarkers()->firstOrCreate(
            ['marker' => 'woo_manual_force_completed'],
            ['marked_by' => $actor?->getAuthIdentifier(), 'marked_at' => now()],
        );
        $trackingFingerprint = hash('sha256', $number);
        $this->manualSync->completeVerifiedManualOverride(
            $order, $actor,
            'verified_tracking_sha256:'.$trackingFingerprint.'; 已回读确认 Woo 母订单 #'.$providerId.' AfterShip Tracking 和 Completed。'
        );
        $order->operationalMarkers()->firstOrCreate(
            ['marker' => 'woo_manual_tracking_verified'],
            ['marked_by' => $actor?->getAuthIdentifier(), 'marked_at' => now()],
        );

        return [
            'status' => 'completed',
            'written' => (bool) ($tracking['written'] ?? false) || (bool) ($status['written'] ?? false),
            'root_order_id' => (int) $root->getKey(),
            'provider_order_id' => $providerId,
            'order_status_sync' => 'completed',
            'manual_override' => true,
        ];
    }

    private function carrierSlug(string $carrier): string
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', $carrier)) ?? $carrier));
        foreach ((array) config('erp_logistics.carriers', []) as $definition) {
            $aliases = [...(array) ($definition['aliases'] ?? []), (string) ($definition['value'] ?? '')];
            foreach ($aliases as $alias) {
                $value = strtolower(trim(preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', (string) $alias)) ?? (string) $alias));
                if ($normalized !== '' && $value === $normalized) {
                    return (string) $definition['aftership_slug'];
                }
            }
        }

        return 'other';
    }
}
