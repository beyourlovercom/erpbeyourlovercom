<?php

namespace App\Modules\B2B\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class B2BTradeOrder extends Model
{
    protected $table = 'b2b_trade_orders';
    protected $guarded = ['id'];
    protected function casts(): array
    {
        return [
            'receivable_micros' => 'integer',
            'quote_snapshot' => 'array',
            'payment_due_on' => 'date',
            'approved_at' => 'datetime',
        ];
    }
    public function organization(): BelongsTo { return $this->belongsTo(B2BOrganization::class,'organization_id'); }
    public function opportunity(): BelongsTo { return $this->belongsTo(B2BSalesOpportunity::class,'opportunity_id'); }
    public function quote(): BelongsTo { return $this->belongsTo(B2BSalesQuote::class,'quote_id'); }
    public function evidence(): HasMany { return $this->hasMany(B2BTradeReceiptEvidence::class,'trade_order_id'); }
    public function auditEvents(): HasMany { return $this->hasMany(B2BTradeAuditEvent::class,'trade_order_id'); }
}
