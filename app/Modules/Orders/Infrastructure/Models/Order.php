<?php

namespace App\Modules\Orders\Infrastructure\Models;

use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderSourceType;
use App\Modules\Orders\Application\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $fillable = [
        'internal_order_number', 'erp_order_id', 'source_type', 'order_kind', 'external_order_number', 'order_date',
        'order_note', 'owner_id', 'order_status', 'currency',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'source_type' => OrderSourceType::class,
            'order_kind' => OrderKind::class,
            'order_status' => OrderStatus::class,
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo('App\\Modules\\Identity\\Infrastructure\\Models\\User', 'owner_id');
    }

    public function address(): HasOne
    {
        return $this->hasOne(OrderAddress::class);
    }

    public function externalReferences(): HasMany
    {
        return $this->hasMany(OrderExternalReference::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function shipment(): HasOne
    {
        return $this->hasOne('App\\Modules\\Logistics\\Infrastructure\\Models\\Shipment');
    }

    public function manualSync(): HasOne
    {
        return $this->hasOne('App\\Modules\\Sync\\Infrastructure\\Models\\ManualSync');
    }

    public function financials(): HasOne
    {
        return $this->hasOne('App\\Modules\\Finance\\Infrastructure\\Models\\OrderFinancial');
    }

    public function costEntries(): HasMany
    {
        return $this->hasMany('App\\Modules\\Finance\\Infrastructure\\Models\\OrderCostEntry');
    }

    public function operationalMarkers(): HasMany
    {
        return $this->hasMany(OrderOperationalMarker::class);
    }

}
