<?php

namespace App\Modules\Logistics\Infrastructure\Xlwms;

use Illuminate\Database\Eloquent\Model;

/**
 * Exactly one exceptional outbound SKU resolution per warehouse and ERP SKU.
 * An OMS product existing in a catalog does NOT prove two physical products
 * are equivalent. Only a reviewed active row can affect outbound payloads.
 */
final class WarehouseSkuMapping extends Model
{
    protected $table = 'warehouse_sku_mappings';

    protected $fillable = [
        'warehouse_id','provider','erp_sku','erp_sku_norm','oms_sku',
        'status','evidence_note','confirmed_by','confirmed_at'
    ];

    protected $casts = ['confirmed_at' => 'datetime'];

    public function isConfirmed(): bool
    {
        return $this->status === 'active'
            && $this->confirmed_at !== null
            && $this->confirmed_by !== null
            && filled($this->evidence_note)
            && filled($this->oms_sku);
    }
}
