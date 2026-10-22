<?php

namespace App\Modules\B2B\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class B2BSalesStageEvent extends Model
{
    protected $table = 'b2b_sales_stage_events';
    protected $guarded = ['id'];
    protected function casts(): array
    {
        return ['entered_at' => 'datetime'];
    }
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(B2BSalesOpportunity::class, 'opportunity_id');
    }
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
