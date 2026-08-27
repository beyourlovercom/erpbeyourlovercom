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

    public function saveDraft(Model $order, array $attributes, ?Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($order, $attributes, $actor): Model {
            $orderClass = get_class($order);
            $lockedOrder = $orderClass::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($lockedOrder->order_status?->value === 'pending_shipment', 409, '只有待发货订单可以保存履约草稿');

            $shipment = $lockedOrder->shipment()->firstOrCreate(['order_id' => $lockedOrder->getKey()]);
            $before = $this->snapshot($shipment);
            $shipment->fill([
                'shipment_status' => 'pending',
                'warehouse_id' => $attributes['warehouse_id'] ?? null,
                'warehouse_selection_source' => isset($attributes['warehouse_id']) ? 'manual' : null,
                'carrier' => $attributes['carrier'] ?? null,
                'service' => $attributes['service'] ?? null,
                'carrier_selection_source' => ($attributes['carrier'] ?? null) || ($attributes['service'] ?? null) ? 'manual' : null,
                'tracking_number' => $attributes['tracking_number'] ?? null,
                'logistics_note' => $attributes['logistics_note'] ?? null,
                'handled_by' => $actor?->getAuthIdentifier(),
            ]);
            $changed = $shipment->isDirty();
            $shipment->save();

            if ($changed) {
                $this->record($actor, 'shipment.fulfillment_draft_saved', $shipment, $before, $this->snapshot($shipment), 'manual_fulfillment_draft');
            }

            return $lockedOrder->fresh(['items', 'owner', 'shipment.warehouse', 'manualSync']);
        });
    }

    public function markShipped(Model $order, array $attributes, ?Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($order, $attributes, $actor): Model {
            $orderClass = get_class($order);
            $lockedOrder = $orderClass::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $shipment = $lockedOrder->shipment()->firstOrCreate(['order_id' => $lockedOrder->getKey()]);
            $before = $this->snapshot($shipment);
            $shipment->fill([
                'shipment_status' => 'shipped',
                'warehouse_id' => $attributes['warehouse_id'] ?? $shipment->warehouse_id,
                'warehouse_selection_source' => array_key_exists('warehouse_id', $attributes) && $attributes['warehouse_id'] !== null ? 'manual' : $shipment->warehouse_selection_source,
                'carrier' => $attributes['carrier'],
                'service' => $attributes['service'] ?? $shipment->service,
                'carrier_selection_source' => array_key_exists('service', $attributes) || array_key_exists('carrier', $attributes) ? 'manual' : $shipment->carrier_selection_source,
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
                $this->record($actor, 'shipment.marked_shipped', $shipment, $before, $this->snapshot($shipment), 'manual_fulfillment');
            }

            return $lockedOrder->fresh(['items', 'owner', 'shipment.warehouse', 'manualSync', 'financials', 'costEntries']);
        });
    }

    public function reopen(Model $order, ?Authenticatable $actor, ?string $note = null): Model
    {
        return DB::transaction(function () use ($order, $actor, $note): Model {
            $orderClass = get_class($order);
            $lockedOrder = $orderClass::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $shipment = $lockedOrder->shipment()->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($shipment);
            $shipment->fill([
                'shipment_status' => 'pending',
                'carrier' => null,
                'service' => null,
                'carrier_selection_source' => null,
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
                $this->record($actor, 'shipment.reopened', $shipment, $before, $this->snapshot($shipment), 'shipment_correction');
            }

            return $lockedOrder->fresh(['items', 'owner', 'shipment.warehouse', 'manualSync', 'financials', 'costEntries']);
        });
    }

    private function snapshot(Model $shipment): array
    {
        return $shipment->only([
            'shipment_status', 'warehouse_id', 'warehouse_selection_source', 'carrier', 'service',
            'carrier_selection_source', 'tracking_number', 'shipped_at', 'logistics_note', 'handled_by',
        ]);
    }

    private function record(?Authenticatable $actor, string $action, Model $shipment, array $before, array $after, string $reason): void
    {
        $this->audit->record(
            $actor ? AuditActor::user($actor, AuditSource::InternalSystem) : AuditActor::system(AuditSource::InternalSystem),
            $action,
            get_class($shipment),
            $shipment->getKey(),
            before: $before,
            after: $after,
            reason: $reason,
        );
    }
}
