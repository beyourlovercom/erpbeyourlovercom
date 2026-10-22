<?php

namespace App\Modules\B2B\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class B2BContact extends Model
{
    protected $table = 'b2b_contacts';

    protected $fillable = [
        'organization_id', 'name', 'job_title',
        'email_normalized', 'phone', 'is_primary',
    ];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(B2BOrganization::class, 'organization_id');
    }
}
