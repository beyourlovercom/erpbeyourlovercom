<?php

namespace App\Modules\B2B\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class B2BCaseActivity extends Model
{
    public $timestamps = false;

    protected $table = 'b2b_case_activities';

    protected $fillable = [
        'case_id', 'actor_user_id', 'activity_type', 'body_text',
        'metadata', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'occurred_at' => 'datetime'];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(B2BCase::class, 'case_id');
    }
}
