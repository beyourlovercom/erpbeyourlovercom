<?php

namespace App\Modules\Products\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

final class ProductParameterAccessory extends Model
{
    protected $fillable = ['product_id', 'approved_submission_id', 'name', 'quantity', 'unit', 'notes'];
    protected $casts = ['quantity' => 'decimal:3'];
}
