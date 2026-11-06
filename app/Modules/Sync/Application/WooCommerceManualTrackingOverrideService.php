<?php

namespace App\Modules\Sync\Application;

use App\Modules\Orders\Application\OrderWorkflowStatusService;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\Contracts\WooCommerceCustomerNoteGateway;
use App\Modules\Sync\Application\Contracts\WooCommerceTrackingGateway;
use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

final class WooCommerceManualTrackingOverrideService
{
    public function __construct(
        private readonly WooCommerceTrackingGateway $gateway,
        private readonly ManualSyncService $manualSync,
        private readonly OrderWorkflowStatusService $workflowStatus,
        private readonly WooCommerceCustomerNoteGateway $customerNotes,
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
        // An archived ERP order must be restored before changing its
        // workflow to Shared; reject BEFORE changing WooCommerce.
        if ($order->trashed() || $order->archived_at !== null || $order->isOperationallyArchived()) {
            throw new DomainException('归档订单请先恢复，才能同步官网并更新为已通知。');
        }
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
        $beforeStatus = $this->gateway->inspectOrderStatus($providerId);
        $parentAlreadyManuallyCompleted = $root->operationalMarkers()
            ->where('marker', 'woo_manual_force_completed')->exists();
        if ($beforeStatus !== 'processing') {
            // After A completed the Woo mother, B/C may still add their own
            // AfterShip package without modifying a non-processing Woo state.
            // Otherwise even an operator may NOT overwrite cancelled,
            // refunded, failed, on-hold, pending or unrelated completed orders.
            if ($beforeStatus !== 'completed' || ! $parentAlreadyManuallyCompleted) {
                throw new DomainException(
                    '官网母订单当前状态是 '.$beforeStatus.'，只允许 processing 订单新增 Tracking 并转 completed；'
                    .'仅已由本流程完成的母订单，允许继续补充或重试包裹通知，且不修改官网状态。'
                );
            }
        }
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

        // Never bypass protected WooCommerce statuses. The gateway checks
        // remote status again before writing, covering status races.
        $tracking = $this->gateway->syncTracking(
            $providerId, $slug, $number, $shipDate, $lineItems
        );
        if (! ($tracking['verified'] ?? false)) {
            throw new \RuntimeException('官网 AfterShip Tracking 写入后回读未通过。');
        }

        // Only a Woo order that STARTED as processing may be updated to
        // completed. If the mother was completed earlier by A, B/C only
        // append AfterShip Tracking without a second status mutation.
        $status = $beforeStatus === 'processing'
            ? $this->gateway->syncOrderStatus($providerId, 'completed')
            : ['status' => $this->gateway->inspectOrderStatus($providerId),
                'verified' => true, 'written' => false];
        if (! ($status['verified'] ?? false) || ($status['status'] ?? '') !== 'completed') {
            throw new \RuntimeException('官网母订单 Completed 状态回读未通过，物流可能已写入，请检查官网。');
        }

        // Persist proof that THIS workflow completed the Woo mother BEFORE
        // attempting email notification. If Woo accepts a completed status
        // but the Customer Note API times out, the next click can safely
        // recover on the already-completed mother (never any unrelated one).
        $root->operationalMarkers()->firstOrCreate(
            ['marker' => 'woo_manual_force_completed'],
            ['marked_by' => $actor?->getAuthIdentifier(), 'marked_at' => now()],
        );

        // First shipment: Woo processing -> completed triggers the site's
        // existing Completed email. In particular A must NEVER generate an
        // additional Customer Note. Only later B/C/... parcels on an already
        // completed mother can send one note each.
        //
        // A rare B-first shipment which changes processing -> completed also
        // uses only the Completed email (no duplicate first-package mail).
        $suffix = strtoupper(trim((string) $order->fulfillment_suffix));
        $isLaterSplitPackage = $order->fulfillment_parent_id !== null
            && preg_match('/^[B-Z][0-9]*$/', $suffix) === 1;
        $shouldSendCustomerNote = $isLaterSplitPackage && $beforeStatus === 'completed';
        $notice = null;

        if ($shouldSendCustomerNote) {
            // WWW Customer Note email is already enabled. Confirm the
            // customer-visible note by remote GET before ERP 2.2 Shared.
            // Note verification confirms handoff, not inbox delivery.
            $notice = $this->customerNotes->notifyPackage($providerId, $slug, $number);
            if (! ($notice['verified'] ?? false)) {
                throw new \RuntimeException('官网客户通知没有通过回读，ERP 暂不标记 2.2 已通知。');
            }
        }
        if ($this->gateway->inspectOrderStatus($providerId) !== 'completed') {
            throw new \RuntimeException('官网母订单状态在通知期间发生变化；ERP 暂不标记 2.2 已通知。');
        }

        // Remember the explicit owner-approved override: a subsequent
        // partial-shipment automatic status projection must not undo it.
        // Local state and workflow/history/audit must be committed together.
        // Never hold the ERP transaction across external WooCommerce requests.
        DB::transaction(function () use ($order, $actor, $number, $providerId, $notice, $beforeStatus): void {
            $trackingFingerprint = hash('sha256', $number);
            $this->manualSync->completeVerifiedManualOverride(
                $order, $actor,
                'verified_tracking_sha256:'.$trackingFingerprint.'; 已回读确认 Woo 母订单 #'.$providerId
                .' AfterShip Tracking 和 Completed 已确认。'
                .($notice !== null ? ' Customer Note #'.$notice['note_id'].' 已接受。'
                    : ($beforeStatus === 'processing' ? ' 首次发货使用 Woo Completed 邮件，无 Customer Note。'
                        : ' 本包裹按规则不创建 Customer Note。'))
            );
            $order->operationalMarkers()->firstOrCreate(
                ['marker' => 'woo_manual_tracking_verified'],
                ['marked_by' => $actor?->getAuthIdentifier(), 'marked_at' => now()],
            );
            // Only the clicked ERP fulfillment becomes 2.2 Shared.
            // Other A/B/C children and canonical shipment state are unchanged.
            $this->workflowStatus->setAfterVerifiedWebsiteTracking($order, $actor);
        });

        return [
            'status' => 'completed',
            'written' => (bool) ($tracking['written'] ?? false)
                || (bool) ($status['written'] ?? false)
                || (bool) ($notice['created'] ?? false),
            'root_order_id' => (int) $root->getKey(),
            'provider_order_id' => $providerId,
            'order_status_sync' => 'completed',
            'manual_override' => true,
            'customer_note_id' => $notice['note_id'] ?? null,
            'customer_note_created' => (bool) ($notice['created'] ?? false),
            'notification_channel' => $shouldSendCustomerNote ? 'customer_note'
                : ($beforeStatus === 'processing' ? 'woo_completed_email' : 'no_new_notification'),
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
