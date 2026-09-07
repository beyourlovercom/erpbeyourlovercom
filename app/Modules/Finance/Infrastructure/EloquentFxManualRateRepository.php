<?php

namespace App\Modules\Finance\Infrastructure;

use App\Modules\Finance\Application\Contracts\FxManualRateRepository;
use App\Modules\Finance\Infrastructure\Models\FxManualRate;
use Illuminate\Support\Facades\DB;

final class EloquentFxManualRateRepository implements FxManualRateRepository
{
    public function all(): array
    {
        return FxManualRate::query()
            ->pluck('manual_rate_to_cny', 'currency')
            ->map(fn ($rate): float => (float) $rate)
            ->all();
    }

    public function replace(array $rates, ?int $updatedBy): void
    {
        DB::transaction(function () use ($rates, $updatedBy): void {
            foreach ($rates as $currency => $rate) {
                $currency = strtoupper($currency);
                if ($rate === null) {
                    FxManualRate::query()->whereKey($currency)->delete();
                    continue;
                }

                FxManualRate::query()->updateOrCreate(
                    ['currency' => $currency],
                    ['manual_rate_to_cny' => $rate, 'updated_by' => $updatedBy],
                );
            }
        });
    }
}
