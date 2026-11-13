<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Logistics\Infrastructure\ShipOut\ShipOutProductionInventoryAudit;

/** Mockable read-only boundary; never mutates provider or inventory. */
class MorePlusPhysicalStockReader
{
    public function __construct(private readonly ShipOutProductionInventoryAudit $audit) {}

    public function snapshot(): array
    {
        return $this->audit->snapshot();
    }
}
