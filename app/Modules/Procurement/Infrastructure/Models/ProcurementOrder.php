<?php

namespace App\Modules\Procurement\Infrastructure\Models;

use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcurementOrder extends Model
{
    protected $fillable = ['procurement_number', 'source_order_id', 'status', 'created_by'];

    public function sourceOrder(): BelongsTo { return $this->belongsTo(Order::class, 'source_order_id'); }
    public function creator(): BelongsTo { return $this->belongsTo('App\\Modules\\Identity\\Infrastructure\\Models\\User', 'created_by'); }
    public function items(): HasMany { return $this->hasMany(ProcurementOrderItem::class); }
}
