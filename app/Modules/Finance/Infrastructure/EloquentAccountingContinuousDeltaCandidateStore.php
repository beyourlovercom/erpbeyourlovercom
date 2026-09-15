<?php

namespace App\Modules\Finance\Infrastructure;

use App\Modules\Finance\Application\Contracts\AccountingContinuousDeltaCandidateStore;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Infrastructure\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class EloquentAccountingContinuousDeltaCandidateStore implements AccountingContinuousDeltaCandidateStore
{
    public function candidates(CarbonImmutable $windowStart, CarbonImmutable $windowEnd, int $limit): Collection
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("SET time_zone = '+00:00'");
        }

        return Order::query()
            ->where('order_kind', OrderKind::Customer->value)
            ->whereDoesntHave('externalReferences', fn (Builder $refs) => $refs
                ->where('provider', 'historical_logistics'))
            ->where(function (Builder $query) use ($windowStart, $windowEnd): void {
                $query->whereBetween('orders.updated_at', [$windowStart, $windowEnd])
                    ->orWhereHas('externalReferences', fn (Builder $refs) => $refs
                        ->where('provider', 'woocommerce')
                        ->whereBetween('updated_at', [$windowStart, $windowEnd]))
                    ->orWhereHas('financials', fn (Builder $financials) => $financials
                        ->whereBetween('updated_at', [$windowStart, $windowEnd]))
                    ->orWhereHas('shipment', fn (Builder $shipment) => $shipment
                        ->whereBetween('updated_at', [$windowStart, $windowEnd]))
                    ->orWhereHas('shipment.billingEvidence', fn (Builder $billing) => $billing
                        ->whereBetween('updated_at', [$windowStart, $windowEnd]));
            })
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }
}
