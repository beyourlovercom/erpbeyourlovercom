<?php

namespace App\Modules\Orders\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = ['product_id', 'product_summary', 'sku', 'source_sku', 'resolved_fulfillment_sku', 'quantity', 'unit_price', 'line_revenue'];

    protected $casts = ['quantity' => 'integer', 'unit_price' => 'decimal:2', 'line_revenue' => 'decimal:2'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo('App\\Modules\\Products\\Infrastructure\\Models\\Product');
    }
}
