<?php

namespace App\Modules\Finance\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderCostEntry extends Model
{
    protected $fillable = ['cost_type', 'amount', 'currency', 'note'];

    protected $casts = ['amount' => 'decimal:2'];

    public function order(): BelongsTo
    {
        return $this->belongsTo('App\\Modules\\Orders\\Infrastructure\\Models\\Order');
    }
}
