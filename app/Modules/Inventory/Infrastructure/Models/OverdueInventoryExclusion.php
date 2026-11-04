<?php

namespace App\Modules\Inventory\Infrastructure\Models;

use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OverdueInventoryExclusion extends Model
{
    protected $fillable = [
        'product_id', 'is_excluded', 'reason', 'updated_by_user_id',
    ];

    protected $casts = [
        'is_excluded' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
