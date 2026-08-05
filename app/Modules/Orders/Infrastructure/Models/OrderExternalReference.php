<?php

namespace App\Modules\Orders\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderExternalReference extends Model
{
    protected $fillable = ['provider', 'reference'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
