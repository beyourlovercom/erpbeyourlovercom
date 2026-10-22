<?php

namespace App\Modules\B2B\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class B2BSalesOpportunity extends Model
{
    protected $table = 'b2b_sales_opportunities';
    protected $guarded = ['id'];
    protected function casts(): array
    {
        return [
            'expected_value_micros' => 'integer',
            'probability_bps' => 'integer',
            'expected_close_date' => 'date',
            'next_follow_up_at' => 'datetime',
            'won_at' => 'datetime',
            'lost_at' => 'datetime',
        ];
    }
    public function organization(): BelongsTo
    {
        return $this->belongsTo(B2BOrganization::class, 'organization_id');
    }
    public function contact(): BelongsTo
    {
        return $this->belongsTo(B2BContact::class, 'contact_id');
    }
    public function inquiryCase(): BelongsTo
    {
        return $this->belongsTo(B2BCase::class, 'case_id');
    }
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
    public function stageEvents(): HasMany
    {
        return $this->hasMany(B2BSalesStageEvent::class, 'opportunity_id');
    }
}
