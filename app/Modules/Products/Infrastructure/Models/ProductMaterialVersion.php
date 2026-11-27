<?php

namespace App\Modules\Products\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProductMaterialVersion extends Model
{
    protected $fillable = ['material_code','code_key','material_name','product_id','binding_source',
        'binding_evidence','factory_stock_approved','factory_stock_evidence','verified_by','verified_at',
        'factory_physical_qty','warehouse_007_qty','last_snapshot_id'];

    protected $casts = ['factory_stock_approved'=>'boolean','verified_at'=>'datetime',
        'factory_physical_qty'=>'integer','warehouse_007_qty'=>'integer'];

    public static function keyFor(string $code): string
    {
        return mb_strtoupper(trim($code), 'UTF-8');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
