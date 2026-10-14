<?php

namespace App\Modules\Products\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

final class ProductParameterTask extends Model
{
    protected $fillable = ['product_id', 'assigned_user_id', 'enabled', 'is_required', 'required_fields', 'updated_by'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'is_required' => 'boolean', 'required_fields' => 'array'];
    }
}
