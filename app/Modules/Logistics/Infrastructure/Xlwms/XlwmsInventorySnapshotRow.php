<?php

namespace App\Modules\Logistics\Infrastructure\Xlwms;

use Illuminate\Database\Eloquent\Model;

final class XlwmsInventorySnapshotRow extends Model
{
    protected $table = 'xlwms_inventory_snapshot_rows';
    protected $fillable = ['snapshot_id','provider_sku','product_id','resolution_status','oms_total','oms_available','oms_locked','oms_in_transit','mismatch_reason'];
    protected $casts = ['oms_total'=>'integer','oms_available'=>'integer','oms_locked'=>'integer','oms_in_transit'=>'integer'];
}
