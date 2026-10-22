<?php

namespace App\Modules\B2B\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class B2BCase extends Model
{
    protected $table = 'b2b_cases';

    protected $fillable = [
        'case_number', 'organization_id', 'primary_contact_id', 'owner_id', 'title',
        'source_channel', 'source_connection_key', 'source_external_id', 'source_dedupe_key',
        'source_site_key', 'country_code', 'product_interest', 'requested_quantity', 'inquiry_kind',
        'priority', 'case_status', 'case_stage', 'handling_state', 'first_inbound_at',
        'last_inbound_at', 'first_action_at', 'last_action_at', 'attention_since_at',
        'next_follow_up_at', 'closed_at', 'loss_reason_code',
    ];

    protected function casts(): array
    {
        return [
            'requested_quantity' => 'integer',
            'first_inbound_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'first_action_at' => 'datetime',
            'last_action_at' => 'datetime',
            'attention_since_at' => 'datetime',
            'next_follow_up_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(B2BOrganization::class, 'organization_id');
    }

    public function primaryContact(): BelongsTo
    {
        return $this->belongsTo(B2BContact::class, 'primary_contact_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(B2BCaseActivity::class, 'case_id');
    }
}
