<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Sync\Application\ManualSyncService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class ShipmentService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly ManualSyncService $manualSync,
    ) {}

    public function markShipped(Model $order, array $attributes, ?Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($order, $attributes, $actor): Model {
            $orderClass = get_class($order);
            $lockedOrder = $orderClass::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $shipment = $lockedOrder->shipment()->firstOrCreate(['order_id' => $lockedOrder->getKey()]);
            $before = $shipment->only(['shipment_status', 'carrier', 'tracking_number', 'shipped_at', 'handled_by']);
            $shipment->fill([
                'shipment_status' => 'shipped',
                'carrier' => $attributes['carrier'],
                'tracking_number' => $attributes['tracking_number'],
                'shipped_at' => $attributes['shipped_at'],
                'logistics_note' => $attributes['logistics_note'] ?? null,
                'handled_by' => $actor?->getAuthIdentifier(),
            ]);
            $changed = $shipment->isDirty() || $lockedOrder->order_status?->value !== 'shipped';
            $shipment->save();
            if ($lockedOrder->order_status?->value !== 'shipped') {
                $lockedOrder->forceFill(['order_status' => 'shipped'])->save();
            }
            $this->manualSync->ensurePending($lockedOrder, $actor);
            if ($changed) {
                $this->audit->record(
                    $actor ? AuditActor::user($actor, AuditSource::InternalSystem) : AuditActor::system(AuditSource::InternalSystem),
                    'shipment.marked_shipped',
                    get_class($shipment),
                    $shipment->getKey(),
                    before: $before,
                    after: $shipment->only(['shipment_status', 'carrier', 'tracking_number', 'shipped_at', 'handled_by']),
                    reason: 'manual_fulfillment',
                );
            }

            return $lockedOrder->fresh(['items', 'owner', 'shipment', 'manualSync', 'financials', 'costEntries']);
        });
    }

    public function reopen(Model $order, ?Authenticatable $actor, ?string $note = null): Model
    {
        return DB::transaction(function () use ($order, $actor, $note): Model {
            $orderClass = get_class($order);
            $lockedOrder = $orderClass::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $shipment = $lockedOrder->shipment()->lockForUpdate()->firstOrFail();
            $before = $shipment->only(['shipment_status', 'carrier', 'tracking_number', 'shipped_at', 'logistics_note', 'handled_by']);
            $shipment->fill([
                'shipment_status' => 'pending',
                'carrier' => null,
                'tracking_number' => null,
                'shipped_at' => null,
                'logistics_note' => $note,
                'handled_by' => null,
            ]);
            $changed = $shipment->isDirty() || $lockedOrder->order_status?->value !== 'pending_shipment';
            $shipment->save();
            if ($lockedOrder->order_status?->value !== 'pending_shipment') {
                $lockedOrder->forceFill(['order_status' => 'pending_shipment'])->save();
            }
            $this->manualSync->reopen($lockedOrder, $actor, $note);
            if ($changed) {
                $this->audit->record(
                    $actor ? AuditActor::user($actor, AuditSource::InternalSystem) : AuditActor::system(AuditSource::InternalSystem),
                    'shipment.reopened',
                    get_class($shipment),
                    $shipment->getKey(),
                    before: $before,
                    after: $shipment->only(['shipment_status', 'carrier', 'tracking_number', 'shipped_at', 'logistics_note', 'handled_by']),
                    reason: 'shipment_correction',
                );
            }

            return $lockedOrder->fresh(['items', 'owner', 'shipment', 'manualSync', 'financials', 'costEntries']);
        });
    }
}
