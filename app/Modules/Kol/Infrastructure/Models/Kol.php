<?php

namespace App\Modules\Kol\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Kol extends Model
{
    protected $table = 'kols';

    protected $fillable = [
        'display_name',
        'legal_name',
        'country_code',
        'language',
        'status',
        'owner_id',
        'relationship_stage',
        'cooperation_type',
        'joined_at',
        'archived_at',
        'notes',
        'contact_email',
        'contact_phone',
        'tax_id',
        'payout_method',
        'payout_account',
        'shipping_recipient_name',
        'shipping_country',
        'shipping_province_state',
        'shipping_city',
        'shipping_postal_code',
        'shipping_address_line_1',
        'shipping_address_line_2',
        'contact_whatsapp',
        'other_contact',
        'preferred_contact_method',
        'level',
        'city',
        'categories',
        'products',
        'content_format',
        'product_quantity',
        'store_count',
        'internal_contact',
        'mcn_agency',
        'is_mcn_managed',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'archived_at' => 'datetime',
        'tax_id' => 'encrypted',
        'payout_account' => 'encrypted',
        'categories' => 'array',
        'products' => 'array',
        'product_quantity' => 'integer',
        'store_count' => 'integer',
        'is_mcn_managed' => 'boolean',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(KolSocialAccount::class, 'kol_id');
    }

    public function salesPerformances(): HasMany
    {
        return $this->hasMany(KolSalesPerformance::class, 'kol_id')->orderByDesc('recorded_at');
    }

    public function historicalOrderCount(): int
    {
        return (int) $this->salesPerformances()->sum('order_count');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('owner_id', $userId);
    }

    public function scopeHundredKPlus(Builder $query): Builder
    {
        return $query->whereHas('socialAccounts', function (Builder $q): void {
            $q->where('is_active', true)
                ->whereHas('latestMetric', function (Builder $mq): void {
                    $mq->where('followers', '>=', 100000);
                });
        });
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
