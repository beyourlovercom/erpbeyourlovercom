<?php

namespace App\Modules\Products\Infrastructure\Models;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A manually confirmed physical SKU in exactly one fulfillment warehouse. */
final class ProductWarehouseSku extends Model
{
    protected $fillable = ['product_id', 'warehouse_id', 'sku', 'is_active', 'verified_at', 'verified_by', 'verification_note'];

    protected $casts = ['is_active' => 'boolean', 'verified_at' => 'datetime'];

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }

    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
}
