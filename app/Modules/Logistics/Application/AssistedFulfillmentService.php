<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Inventory\Application\InventoryReservationService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AssistedFulfillmentService
{
    public function __construct(
        private readonly WarehouseRecommendationService $recommendations,
        private readonly InventoryReservationService $reservations,
        private readonly ShipmentService $shipments,
    ) {}

    public function adoptRecommendation(Model $order, Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($order, $actor): Model {
            $lockedOrder = $order::query()->with(['address', 'items', 'shipment'])->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $recommendation = $this->recommendations->recommend(
                $lockedOrder->address?->country,
                $lockedOrder->items->map(fn ($item): array => ['product_id' => $item->product_id, 'quantity' => (int) $item->quantity])->values()->all(),
            );
            if (($recommendation['status'] ?? null) !== 'recommended' || empty($recommendation['warehouse']['id'])) {
                abort(409, (string) ($recommendation['reason_label'] ?? '当前无法采用系统推荐仓库。'));
            }

            $warehouseId = (int) $recommendation['warehouse']['id'];
            try {
                $this->reservations->reserve(
                    (int) $lockedOrder->getKey(),
                    $warehouseId,
                    'assist-'.$lockedOrder->getKey().'-'.Str::uuid(),
                    null,
                    $actor,
                );
            } catch (\DomainException $e) {
                abort(409, $e->getMessage());
            }

            return $this->shipments->saveDraft($lockedOrder, [
                'warehouse_id' => $warehouseId,
                'warehouse_selection_source' => 'recommended',
            ], $actor);
        });
    }

    public function saveDraft(Model $order, array $attributes, Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($order, $attributes, $actor): Model {
            $active = $this->reservations->activeForOrder((int) $order->getKey());
            $requestedWarehouseId = isset($attributes['warehouse_id']) ? (int) $attributes['warehouse_id'] : null;

            if ($active && $requestedWarehouseId !== (int) $active['warehouse_id']) {
                $this->reservations->release((int) $active['id'], $actor, 'manual_warehouse_override');
            } elseif ($active && $requestedWarehouseId === (int) $active['warehouse_id']) {
                $attributes['warehouse_selection_source'] = 'recommended';
            }

            return $this->shipments->saveDraft($order, $attributes, $actor);
        });
    }

    public function markShipped(Model $order, array $attributes, Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($order, $attributes, $actor): Model {
            $active = $this->reservations->activeForOrder((int) $order->getKey());
            if ($active && (int) ($attributes['warehouse_id'] ?? 0) !== (int) $active['warehouse_id']) {
                abort(409, '发货仓库与已预留库存仓库不一致，请先保存新的人工仓库选择。');
            }

            $updated = $this->shipments->markShipped($order, $attributes, $actor);
            if ($active) {
                $this->reservations->commit((int) $active['id'], $actor);
            }

            return $updated;
        });
    }
}
