<?php

namespace App\Modules\Logistics\Experience\Console\Commands;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\Domestic\DomesticCarrierDefaults;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class BackfillDomesticCarrierDefaultsCommand extends Command
{
    protected $signature = 'domestic:backfill-carrier-defaults {--apply : Write approved unconfigured pending factory routes}';
    protected $description = 'Preview or fill factory carrier defaults; never changes manual, dispatched, stockout or unreviewed orders.';

    public function handle(DomesticCarrierDefaults $defaults): int
    {
        $factoryId = Warehouse::query()->whereRaw('UPPER(code) = ?', ['FACTORY'])
            ->where('is_active', true)->value('id');
        if (! $factoryId) {
            $this->error('FACTORY warehouse is missing.');
            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $candidate = 0;
        $changed = 0;
        $skipped = 0;
        $query = Order::query()->eligibleForOperations()
            ->where('order_status', 'pending_shipment')
            ->whereNull('archived_at')
            ->whereDoesntHave('fulfillmentChildren')
            ->whereDoesntHave('externalReferences', fn (Builder $q) => $q->where('provider', 'historical_logistics'))
            ->whereHas('operationalMarkers', fn (Builder $q) => $q->where('marker', 'domestic_reviewed'))
            ->whereDoesntHave('operationalMarkers', fn (Builder $q) => $q->where('marker', 'domestic_stockout'))
            ->whereHas('shipment', fn (Builder $q) => $q->where('warehouse_id', $factoryId)
                ->where('shipment_status', 'pending')
                ->whereNull('shipped_at')->whereNull('tracking_number')
                ->where(fn (Builder $s) => $s->whereNull('carrier_selection_source')
                    ->orWhere('carrier_selection_source', 'automatic')))
            ->orderBy('id');
        $query->chunkById(100, function ($orders) use ($defaults, $apply, &$candidate, &$changed, &$skipped): void {
            foreach ($orders as $order) {
                $candidate++;
                if (! $defaults->recommended($order->loadMissing('address'))) {
                    $skipped++;
                    continue;
                }
                if (! $apply) {
                    continue;
                }
                DB::transaction(function () use ($order, $defaults, &$changed, &$skipped): void {
                    $fresh = Order::query()->with(['shipment', 'address', 'operationalMarkers'])
                        ->whereKey($order->id)->lockForUpdate()->firstOrFail();
                    if ($fresh->shipment?->carrier_selection_source === 'manual'
                        || $fresh->shipment?->shipment_status !== 'pending'
                        || filled($fresh->shipment?->tracking_number)
                        || $fresh->shipment?->shipped_at !== null
                        || ! $fresh->operationalMarkers->contains('marker', 'domestic_reviewed')
                        || $fresh->operationalMarkers->contains('marker', 'domestic_stockout')
                        || $fresh->archived_at !== null || $fresh->trashed()
                        || $fresh->order_status?->value !== 'pending_shipment') {
                        $skipped++;
                        return;
                    }
                    if ($defaults->fill($fresh)) {
                        $changed++;
                    }
                });
            }
        });
        $this->line(($apply ? 'APPLY' : 'DRY RUN')." candidates={$candidate} changed={$changed} skipped={$skipped}");
        return self::SUCCESS;
    }
}
