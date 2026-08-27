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
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(KolSocialAccount::class, 'kol_id');
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
