<?php

namespace App\Modules\Kol\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KolCollaboration extends Model
{
    protected $table = 'kol_collaborations';

    protected $fillable = [
        'kol_id', 'opportunity_id', 'owner_id', 'title', 'post_url', 'cooperation_type', 'status',
        'agreement_amount', 'currency', 'agreed_at', 'planned_publish_at',
        'deliverables', 'fulfillment_required', 'notes',
    ];

    protected $casts = [
        'agreement_amount' => 'decimal:2',
        'agreed_at' => 'date',
        'planned_publish_at' => 'date',
        'fulfillment_required' => 'boolean',
    ];

    public function kol(): BelongsTo { return $this->belongsTo(Kol::class, 'kol_id'); }
    public function opportunity(): BelongsTo { return $this->belongsTo(KolOpportunity::class, 'opportunity_id'); }
    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
    public function products(): HasMany { return $this->hasMany(KolCollaborationProduct::class, 'collaboration_id')->orderBy('id'); }
}
