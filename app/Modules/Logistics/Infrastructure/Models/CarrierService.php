<?php

namespace App\Modules\Logistics\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CarrierService extends Model
{
    protected $table = 'logistics_carrier_services';

    protected $fillable = [
        'carrier_id', 'service_key', 'service_value', 'name_en', 'name_zh',
        'is_active', 'sort_order', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class, 'carrier_id');
    }

    public function displayName(): string
    {
        // The stored value remains unchanged for logistics APIs and historical shipments.
        return trim((string) $this->name_zh) ?: $this->service_value;
    }
}
