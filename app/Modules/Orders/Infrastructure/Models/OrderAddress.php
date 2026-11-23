<?php

namespace App\Modules\Orders\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderAddress extends Model
{
    protected $fillable = [
        'recipient_name', 'phone', 'email', 'company', 'country', 'province_state', 'city',
        'address_line_1', 'address_line_2', 'postal_code', 'recipient_customs_id',
    ];

    protected $casts = [
        'recipient_customs_id' => 'encrypted',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
