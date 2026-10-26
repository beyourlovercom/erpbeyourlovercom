<?php

namespace App\Modules\Logistics\Infrastructure\Xlwms;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class XlwmsInventorySnapshot extends Model
{
    protected $table = 'xlwms_inventory_snapshots';
    protected $fillable = ['warehouse_id','wh_code','checksum','status','fetched_at','applied_at','provider_sku_count','mapped_sku_count','unmatched_sku_count','blocked_sku_count','summary','mismatches','errors'];
    protected $casts = ['fetched_at'=>'datetime','applied_at'=>'datetime','summary'=>'array','mismatches'=>'array','errors'=>'array'];
    public function rows(): HasMany { return $this->hasMany(XlwmsInventorySnapshotRow::class, 'snapshot_id'); }
}
