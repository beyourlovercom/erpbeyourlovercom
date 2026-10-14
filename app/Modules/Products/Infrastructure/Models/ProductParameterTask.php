<?php

namespace App\Modules\Products\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProductParameterTask extends Model
{
    protected $fillable = ['product_id', 'assigned_user_id', 'enabled', 'is_required', 'required_fields', 'updated_by'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'is_required' => 'boolean', 'required_fields' => 'array'];
    }
}
