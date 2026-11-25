<?php

namespace App\Modules\Orders\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = ['product_id', 'source_product_id', 'woocommerce_line_item_id', 'product_summary', 'source_product_summary', 'sku', 'source_sku', 'resolved_fulfillment_sku', 'final_sku', 'quantity', 'unit_price', 'line_revenue'];

    protected $casts = ['source_product_id' => 'integer', 'woocommerce_line_item_id' => 'integer', 'quantity' => 'integer', 'unit_price' => 'decimal:2', 'line_revenue' => 'decimal:2'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** An explicitly resolved unbound source remains unbound; never substitute the outbound ID. */
    public function fulfillmentSourceProductId(): ?int
    {
        if ($this->source_product_id) return (int) $this->source_product_id;
        if ($this->source_sku && $this->source_product_summary !== null && $this->resolved_fulfillment_sku) return null;

        return $this->product_id ? (int) $this->product_id : null;
    }

    public function sourceProduct(): BelongsTo
    {
        return $this->belongsTo('App\\Modules\\Products\\Infrastructure\\Models\\Product', 'source_product_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo('App\\Modules\\Products\\Infrastructure\\Models\\Product');
    }
}
