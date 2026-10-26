<?php

namespace App\Modules\Logistics\Infrastructure\Xlwms;

use Illuminate\Database\Eloquent\Model;

final class XlwmsInventoryLotReconciliation extends Model
{
    protected $table = 'xlwms_inventory_lot_reconciliations';
    protected $fillable = ['snapshot_id', 'warehouse_id', 'product_id', 'qoh_before', 'qoh_after', 'dated_lot_quantity_before', 'dated_lot_quantity_after', 'unknown_age_before', 'unknown_age_after', 'physical_legacy_mismatch', 'lot_adjustments', 'source_checksum'];
    protected $casts = ['physical_legacy_mismatch' => 'array', 'lot_adjustments' => 'array'];
}
