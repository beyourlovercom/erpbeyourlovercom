<?php

namespace App\Modules\Orders\Infrastructure\Models;

use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderSourceType;
use App\Modules\Orders\Application\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'customer_id', 'fulfillment_parent_id', 'fulfillment_suffix', 'fulfillment_role', 'fulfillment_origin_id', 'fulfillment_reason', 'fulfillment_note',
        'internal_order_number', 'erp_order_id', 'source_type', 'order_kind', 'external_order_number', 'order_date',
        'order_note', 'owner_id', 'kol_id', 'order_status', 'currency',
        'workflow_status', 'workflow_revision',
        'domestic_reject_reason', 'domestic_rejected_at', 'domestic_rejected_by',
        'operational_archived_at', 'operational_archived_by',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'source_type' => OrderSourceType::class,
            'order_kind' => OrderKind::class,
            'order_status' => OrderStatus::class,
            'workflow_revision' => 'integer',
            'domestic_rejected_at' => 'datetime',
            'deleted_at' => 'datetime',
            'archived_at' => 'datetime',
            'operational_archived_at' => 'datetime',
            'operational_archived_by' => 'integer',
        ];
    }

    public function fulfillmentParent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'fulfillment_parent_id');
    }

    public function fulfillmentChildren(): HasMany
    {
        return $this->hasMany(self::class, 'fulfillment_parent_id');
    }

    public function originalFulfillmentChildren(): HasMany
    {
        return $this->hasMany(self::class, 'fulfillment_parent_id')
            ->where(fn ($query) => $query->whereNull('fulfillment_role')->orWhere('fulfillment_role', 'original'));
    }

    public function replacementFulfillments(): HasMany
    {
        return $this->hasMany(self::class, 'fulfillment_parent_id')->where('fulfillment_role', 'replacement');
    }

    public function fulfillmentOrigin(): BelongsTo
    {
        return $this->belongsTo(self::class, 'fulfillment_origin_id');
    }

    public function kol(): BelongsTo
    {
        return $this->belongsTo(Kol::class, 'kol_id');
    }

    public function kolCollaboration(): HasOne
    {
        return $this->hasOne(\App\Modules\Kol\Infrastructure\Models\KolCollaboration::class, 'order_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo('App\\Modules\\Identity\\Infrastructure\\Models\\User', 'owner_id');
    }

    public function operationalArchivedByUser(): BelongsTo
    {
        return $this->belongsTo('App\\Modules\\Identity\\Infrastructure\\Models\\User', 'operational_archived_by');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
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

    public function fulfillmentAllocation(): HasOne
    {
        return $this->hasOne('App\\Modules\\Logistics\\Infrastructure\\Models\\FulfillmentAllocation');
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

    public function procurementOrder(): HasOne
    {
        return $this->hasOne('App\\Modules\\Procurement\\Infrastructure\\Models\\ProcurementOrder', 'source_order_id');
    }

    public function logisticsReview(): HasOne
    {
        return $this->hasOne('App\\Modules\\Logistics\\Infrastructure\\Models\\LogisticsReview');
    }

    public function shipmentAttempts(): HasMany
    {
        return $this->hasMany('App\\Modules\\Logistics\\Infrastructure\\Models\\ShipmentAttempt');
    }

    public function workflowStatusHistory(): HasMany
    {
        return $this->hasMany(OrderWorkflowStatusHistory::class)->orderBy('revision');
    }

    public function scopeEligibleForOperations(Builder $query, bool $includeLegacyTrash = true): Builder
    {
        return $query
            ->whereNull('operational_archived_at')
            ->whereDoesntHave('fulfillmentParent', function (Builder $parent) use ($includeLegacyTrash): void {
                $parent->withTrashed();
                $parent->whereNotNull('operational_archived_at');
                if ($includeLegacyTrash) {
                    $parent->orWhereNotNull('archived_at')->orWhereNotNull('deleted_at');
                }
            })
            ->when($includeLegacyTrash, fn (Builder $eligible): Builder => $eligible->whereNull('archived_at'));
    }

    public function isOperationallyArchived(): bool
    {
        $parent = $this->relationLoaded('fulfillmentParent')
            ? $this->getRelation('fulfillmentParent')
            : $this->fulfillmentParent()->withTrashed()->first();

        return $this->operational_archived_at !== null
            || $parent?->operational_archived_at !== null;
    }
}
