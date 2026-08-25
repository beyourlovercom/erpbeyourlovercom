<?php

namespace App\Modules\Products\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'woocommerce_product_id',
        'product_type',
        'sku',
        'gtin',
        'name',
        'weight_kg',
        'sale_price',
        'regular_price',
    ];

    protected $casts = [
        'woocommerce_product_id' => 'integer',
        'weight_kg' => 'decimal:4',
        'sale_price' => 'decimal:2',
        'regular_price' => 'decimal:2',
    ];
}
