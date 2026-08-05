<?php

namespace App\Modules\Finance\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderFinancial extends Model
{
    protected $fillable = [
        'merchandise_list_amount', 'discount_amount', 'shipping_revenue',
        'amount_paid', 'refund_amount', 'currency',
    ];

    protected $casts = [
        'merchandise_list_amount' => 'decimal:2', 'discount_amount' => 'decimal:2',
        'shipping_revenue' => 'decimal:2', 'amount_paid' => 'decimal:2',
        'refund_amount' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo('App\\Modules\\Orders\\Infrastructure\\Models\\Order');
    }
}
