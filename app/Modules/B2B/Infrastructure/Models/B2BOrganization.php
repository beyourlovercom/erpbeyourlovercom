<?php

namespace App\Modules\B2B\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class B2BOrganization extends Model
{
    protected $table = 'b2b_organizations';

    protected $fillable = [
        'display_name', 'country_code', 'website_url',
        'company_id', 'owner_id', 'stage',
    ];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(B2BContact::class, 'organization_id');
    }
}
