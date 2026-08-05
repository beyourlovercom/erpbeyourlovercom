<?php

namespace App\Modules\Logistics\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Shipment extends Model
{
    protected $fillable = ['shipment_status', 'carrier', 'tracking_number', 'shipped_at', 'logistics_note', 'handled_by'];

    protected $casts = ['shipped_at' => 'datetime'];

    public function order(): BelongsTo
    {
        return $this->belongsTo('App\\Modules\\Orders\\Infrastructure\\Models\\Order');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo('App\\Modules\\Identity\\Infrastructure\\Models\\User', 'handled_by');
    }
}
