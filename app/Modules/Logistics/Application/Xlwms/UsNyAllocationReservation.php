<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Application\Contracts\InventoryReservationRepository;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Inventory\Infrastructure\UsNyStockClock;
use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Called inside the shipment/allocation transaction after final SKU materialization. */
final class UsNyAllocationReservation
{
    public function __construct(private readonly InventoryReservationRepository $reservations, private readonly AuditRecorder $audit) {}

    public function sync(Model $order, int $warehouseId, ?Authenticatable $actor, string $warehouseCode = 'US-NY'): void
    {
        $ny = (int) Warehouse::query()->where('code', $warehouseCode)->value('id');
        if (! $ny) {
            return;
        }
        $active = $this->reservations->activeForOrder((int) $order->id);
        $targetNy = $warehouseId === $ny;
        if (! $targetNy && (! $active || (int) $active['warehouse_id'] !== $ny)) {
            return;
        }
        $actorId = $actor ? (int) $actor->getAuthIdentifier() : null;
        if ($active) {
            $actual = $order->items()->get(['product_id', 'quantity'])->groupBy('product_id')
                ->map(fn ($items) => (int) $items->sum('quantity'))->sortKeys()->all();
            $prior = collect($active['items'])->pluck('quantity', 'product_id')->sortKeys()->all();
            if ($targetNy && (int) $active['warehouse_id'] === $ny && $actual === $prior) {
                return;
            }
            $result = $this->reservations->release((int) $active['id'], $actorId, ($warehouseCode === 'CA' ? 'ca_allocation_changed' : 'us_ny_allocation_changed'));
            $this->record($actor, 'inventory.reservation.released', $result, $warehouseCode);
        }
        if (! $targetNy) {
            return;
        }
        try {
            $result = $this->reservations->reserve((int) $order->id, $ny, ($warehouseCode === 'CA' ? 'ca-alloc-' : 'ny-alloc-').$order->id.'-'.Str::uuid(), null, $actorId);
            $this->record($actor, 'inventory.reservation.reserved', $result, $warehouseCode);
        } catch (DomainException $error) {
            abort(409, $error->getMessage());
        }
    }

    public function commit(Model $order, int $warehouseId, ?Authenticatable $actor, string $warehouseCode = 'US-NY'): void
    {
        $ny = (int) Warehouse::query()->where('code', $warehouseCode)->value('id');
        if (! $ny || $warehouseId !== $ny) {
            return;
        }
        $active = $this->reservations->activeForOrder((int) $order->id);
        if (! $active || (int) $active['warehouse_id'] !== $ny) {
            return;
        } // pre-cutover legacy shipments
        $result = $this->reservations->commit((int) $active['id'], $actor ? (int) $actor->getAuthIdentifier() : null);
        $this->record($actor, 'inventory.reservation.committed', $result, $warehouseCode);
    }

    /** The order may consume its own hold, never another order's reserved stock. */
    public function dispatchBlockers(Model $order, string $warehouseCode = 'US-NY'): array
    {
        $ny = (int) Warehouse::query()->where('code', $warehouseCode)->value('id');
        $active = $this->reservations->activeForOrder((int) $order->id);
        $own = $active && (int) $active['warehouse_id'] === $ny
            ? collect($active['items'])->pluck('quantity', 'product_id') : collect();
        $required = $order->items()->get(['product_id', 'quantity'])->groupBy('product_id')
            ->map(fn ($items) => (int) $items->sum('quantity'));
        $blockers = [];
        foreach ($required as $pid => $qty) {
            $stock = UsNyStockClock::withEpoch(WarehouseInventory::query())
                ->where('warehouse_id', $ny)->where('product_id', $pid)->first();
            if (! UsNyStockClock::fresh($stock)) {
                $blockers[] = ($warehouseCode === 'CA' ? 'CA_OMS_SKU_NOT_FRESH' : 'US_NY_OMS_SKU_NOT_FRESH');

                continue;
            }
            $other = max(0, (int) $stock->quantity_reserved - (int) $own->get($pid, 0));
            if ($qty < 1 || min((int) $stock->quantity_on_hand, (int) $stock->provider_available_quantity) - $other < $qty) {
                $blockers[] = ($warehouseCode === 'CA' ? 'CA_STOCK_RESERVED_OR_INSUFFICIENT' : 'US_NY_STOCK_RESERVED_OR_INSUFFICIENT');
            }
        }

        return array_values(array_unique($blockers));
    }

    private function record(?Authenticatable $actor, string $action, array $result, string $warehouseCode): void
    {
        if ($result['idempotent'] ?? false) {
            return;
        }
        $this->audit->record($actor ? AuditActor::user($actor, AuditSource::InternalSystem) : AuditActor::system(AuditSource::InternalSystem),
            $action, 'inventory_reservation', $result['id'], after: $result, reason: $warehouseCode === 'CA' ? 'ca_allocation_reservation' : 'us_ny_allocation_reservation');
    }
}
