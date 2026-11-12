<?php

namespace App\Modules\Logistics\Application\Domestic;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Application\Contracts\InventoryReservationRepository;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Identity\Application\RolePreset;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\NotificationCenter\Application\NotificationPublisher;
use App\Modules\Inventory\Infrastructure\Models\InventoryReservation;
use App\Modules\Orders\Application\OrderWorkflowStatusService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

final class DomesticReviewDecisionService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly OrderWorkflowStatusService $workflow,
        private readonly InventoryReservationRepository $reservations,
        private readonly DomesticCarrierDefaults $carrierDefaults,
        private readonly NotificationPublisher $notifications,
    ) {}

    public function decide(Order $order, string $decision, ?string $reason, Authenticatable $actor): void
    {
        abort_unless(in_array($decision, ['approve', 'restock', 'stockout', 'reject'], true), 422);
        if ($decision === 'reject') {
            abort_unless(mb_strlen(trim((string) $reason)) >= 3, 422, '驳回必须填写原因（至少 3 个字）。');
        }

        DB::transaction(function () use ($order, $decision, $reason, $actor): void {
            $locked = Order::query()->with([
                'shipment', 'operationalMarkers', 'externalReferences', 'fulfillmentParent',
            ])->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $factory = Warehouse::query()->whereRaw('UPPER(code) = ?', ['FACTORY'])->firstOrFail();
            abort_if($locked->archived_at !== null || $locked->isOperationallyArchived() || $locked->trashed(), 409, '归档订单不可审核。');
            abort_unless($locked->order_status?->value === 'pending_shipment', 409, '订单状态不允许进行国内商务审核。');
            abort_unless($locked->shipment && (int) $locked->shipment->warehouse_id === (int) $factory->id, 409, '订单不属于中国工厂仓。');
            abort_unless($locked->shipment->shipment_status === 'pending'
                && blank($locked->shipment->tracking_number) && $locked->shipment->shipped_at === null,
                409, '已进入实际发货的订单不可撤回审核。');
            abort_if($locked->externalReferences->contains('provider', 'historical_logistics'), 409, '历史物流订单不可操作。');

            $before = [
                'workflow_status' => $locked->workflow_status,
                'warehouse_id' => $locked->shipment->warehouse_id,
                'carrier' => $locked->shipment->carrier,
                'service' => $locked->shipment->service,
                'carrier_selection_source' => $locked->shipment->carrier_selection_source,
            ];
            if ($decision === 'restock') {
                abort_unless(
                    $locked->workflow_status === 'out_of_stock'
                    || $locked->operationalMarkers->contains('marker', 'domestic_stockout'),
                    409, '仅缺货订单可以确认已到货。',
                );
            }

            if (in_array($decision, ['approve', 'restock'], true)) {
                // Approved factory orders receive a persisted, country-aware
                // route. Existing staff overrides are never overwritten.
                $this->carrierDefaults->fill($locked);
                $locked->operationalMarkers()->where('marker', 'domestic_stockout')->delete();
                if ($locked->workflow_status === 'out_of_stock') {
                    $this->workflow->set($locked, 'sending', (int) $locked->workflow_revision, $actor);
                }
                $locked->operationalMarkers()->updateOrCreate(
                    ['marker' => 'domestic_reviewed'],
                    ['marked_by' => $actor->getAuthIdentifier(), 'marked_at' => now()],
                );
                $this->clearRejection($locked);
            } elseif ($decision === 'stockout') {
                $locked->operationalMarkers()->where('marker', 'domestic_reviewed')->delete();
                $locked->operationalMarkers()->updateOrCreate(
                    ['marker' => 'domestic_stockout'],
                    ['marked_by' => $actor->getAuthIdentifier(), 'marked_at' => now()],
                );
                $this->releaseReservations([(int) $locked->getKey()], $actor);
                $this->workflow->set($locked, 'out_of_stock', (int) $locked->workflow_revision, $actor);
            } else {
                $root = $locked->fulfillmentParent ?? $locked;
                $siblings = $root->originalFulfillmentChildren()->with('shipment')->get();
                if ($siblings->isNotEmpty()) {
                    abort_if($siblings->contains(fn (Order $sibling): bool =>
                        ! $sibling->shipment || $sibling->shipment->shipment_status !== 'pending'
                        || filled($sibling->shipment->tracking_number) || $sibling->shipment->shipped_at !== null
                    ), 409, '拆单已有发货或异常段，不能整单驳回；请联系运营单独处理。');
                    foreach ($siblings as $sibling) {
                        $this->unassign($sibling, $actor);
                    }
                } else {
                    $this->unassign($locked, $actor);
                }
                $this->releaseReservations(array_unique([
                    (int) $root->getKey(), ...$siblings->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                ]), $actor);
                $root->fulfillmentAllocation?->forceFill(['status' => 'rejected'])->save();
                $root->forceFill([
                    'domestic_reject_reason' => trim((string) $reason),
                    'domestic_rejected_by' => $actor->getAuthIdentifier(),
                    'domestic_rejected_at' => now(),
                ])->save();
                $locked->operationalMarkers()->whereIn('marker', ['domestic_stockout', 'domestic_reviewed'])->delete();
            }

            $this->audit->record(
                AuditActor::user($actor, AuditSource::Web), 'domestic.review_'.$decision,
                Order::class, $locked->getKey(), $before,
                [
                    'decision' => $decision,
                    'review_reason' => $decision === 'reject' ? trim((string) $reason) : null,
                    'carrier' => $locked->shipment->fresh()?->carrier,
                    'service' => $locked->shipment->fresh()?->service,
                    'carrier_selection_source' => $locked->shipment->fresh()?->carrier_selection_source,
                ],
                'domestic_business_review',
            );
            if ($decision === 'reject') {
                $returnedOrder = $locked->fulfillmentParent ?? $locked;
                User::query()->where('is_active', true)
                    ->whereHas('roles', fn ($q) => $q->where('key', RolePreset::Operations->roleKey()))
                    ->get()
                    ->each(fn (User $user) => $this->notifications->publishToUser($user, [
                        'category' => 'logistics',
                        'title' => '国内商务分仓被驳回',
                        'message' => "订单 {$returnedOrder->erp_order_id} 被驳回：" . trim((string) $reason),
                        'action_url' => '/logistics/wait-stock?rejected=1',
                        'entity_type' => 'order',
                        'entity_id' => (string) $returnedOrder->getKey(),
                        'actor_name' => $actor->name ?? null,
                    ]));
            }
        });
    }

    /** @param list<int> $orderIds */
    private function releaseReservations(array $orderIds, Authenticatable $actor): void
    {
        foreach ($orderIds as $orderId) {
            $reservation = $this->reservations->activeForOrder($orderId);
            if (! $reservation) {
                continue;
            }
            $released = $this->reservations->release(
                (int) $reservation['id'], (int) $actor->getAuthIdentifier(), 'domestic_review_return',
            );
            $this->audit->record(
                AuditActor::user($actor, AuditSource::Web), 'inventory.reservation.released',
                InventoryReservation::class, (int) $reservation['id'],
                ['status' => 'reserved'], $released, 'domestic_review_return',
            );
        }
    }

    private function clearRejection(Order $order): void
    {
        $root = $order->fulfillmentParent ?? $order;
        $root->forceFill([
            'domestic_reject_reason' => null,
            'domestic_rejected_by' => null,
            'domestic_rejected_at' => null,
        ])->save();
    }

    private function unassign(Order $order, Authenticatable $actor): void
    {
        $shipment = $order->shipment;
        abort_unless($shipment && $shipment->shipment_status === 'pending'
            && blank($shipment->tracking_number) && $shipment->shipped_at === null, 409, '订单已发货，不能退回待分仓。');
        $shipment->forceFill([
            'warehouse_id' => null, 'warehouse_selection_source' => null,
            'carrier' => null, 'service' => null, 'carrier_service_id' => null,
            'carrier_selection_source' => null, 'handled_by' => null,
        ])->save();
        $order->operationalMarkers()->whereIn('marker', ['domestic_stockout', 'domestic_reviewed'])->delete();
        if ($order->workflow_status === 'out_of_stock') {
            $this->workflow->set($order, 'sending', (int) $order->workflow_revision, $actor);
        }
    }
}
