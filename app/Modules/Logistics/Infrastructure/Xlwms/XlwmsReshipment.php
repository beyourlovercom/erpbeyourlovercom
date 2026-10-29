<?php
namespace App\Modules\Logistics\Infrastructure\Xlwms;
use Illuminate\Database\Eloquent\Model;
final class XlwmsReshipment extends Model {
    protected $table = 'xlwms_reshipments';
    protected $guarded = ['id'];
    protected $casts = ['sku_quantity_snapshot'=>'array','oms_checked_at'=>'datetime'];
}
