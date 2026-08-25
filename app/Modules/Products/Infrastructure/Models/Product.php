<?php

namespace App\Modules\Products\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'woocommerce_product_id',
        'source_type',
        'product_type',
        'sku',
        'gtin',
        'name',
        'unit',
        'is_active',
        'weight_kg',
        'sale_price',
        'regular_price',
        'shipping_weight_kg',
        'length_cm',
        'width_cm',
        'height_cm',
        'country_of_origin',
        'hs_code',
        'standard_production_cost',
        'standard_packaging_cost',
        'standard_purchase_cost',
        'cost_currency',
        'cost_note',
        'notes',
    ];

    protected $casts = [
        'woocommerce_product_id' => 'integer',
        'is_active' => 'boolean',
        'weight_kg' => 'decimal:4',
        'sale_price' => 'decimal:2',
        'regular_price' => 'decimal:2',
        'shipping_weight_kg' => 'decimal:4',
        'length_cm' => 'decimal:2',
        'width_cm' => 'decimal:2',
        'height_cm' => 'decimal:2',
        'standard_production_cost' => 'decimal:4',
        'standard_packaging_cost' => 'decimal:4',
        'standard_purchase_cost' => 'decimal:4',
    ];
}
