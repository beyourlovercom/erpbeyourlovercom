<?php

namespace App\Modules\Orders\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OrderWorkflowStatusHistory extends Model
{
    public $timestamps = false;
    protected $fillable = ['order_id', 'actor_id', 'from_status', 'to_status', 'revision', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
}
