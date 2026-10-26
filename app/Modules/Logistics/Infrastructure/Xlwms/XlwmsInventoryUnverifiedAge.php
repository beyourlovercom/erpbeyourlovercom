<?php

namespace App\Modules\Logistics\Infrastructure\Xlwms;

use Illuminate\Database\Eloquent\Model;

final class XlwmsInventoryUnverifiedAge extends Model
{
    protected $table = 'xlwms_inventory_unverified_ages';
    protected $fillable = ['warehouse_id', 'product_id', 'unknown_age_quantity', 'last_snapshot_id'];
    protected $casts = ['unknown_age_quantity' => 'integer', 'last_snapshot_id' => 'integer'];
}
