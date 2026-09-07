<?php

namespace App\Modules\Finance\Application\Contracts;

interface FxManualRateRepository
{
    /** @return array<string, float> */
    public function all(): array;

    /** @param array<string, float|null> $rates */
    public function replace(array $rates, ?int $updatedBy): void;
}
