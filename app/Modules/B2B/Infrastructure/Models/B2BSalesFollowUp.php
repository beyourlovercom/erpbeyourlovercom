<?php

namespace App\Modules\B2B\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class B2BSalesFollowUp extends Model
{
    protected $table = 'b2b_sales_followups';
    protected $guarded = ['id'];
    protected function casts(): array
    {
        return ['happened_at'=>'datetime','next_follow_up_at'=>'datetime'];
    }
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(B2BSalesOpportunity::class, 'opportunity_id');
    }
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
    public function attachments(): HasMany
    {
        return $this->hasMany(B2BSalesFollowUpAttachment::class, 'follow_up_id')->orderBy('id');
    }
}
