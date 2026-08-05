<?php

namespace App\Modules\Orders\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = ['product_summary', 'sku', 'quantity', 'unit_price', 'line_revenue'];

    protected $casts = ['quantity' => 'integer', 'unit_price' => 'decimal:2', 'line_revenue' => 'decimal:2'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
