<?php

namespace App\Modules\B2B\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class B2BSalesQuote extends Model
{
    protected $table = 'b2b_sales_quotes';
    protected $guarded = ['id'];
    protected function casts(): array
    {
        return [
            'version'=>'integer','subtotal_micros'=>'integer','discount_micros'=>'integer',
            'tax_micros'=>'integer','total_micros'=>'integer','valid_until'=>'date','line_items'=>'array',
        ];
    }
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(B2BSalesOpportunity::class, 'opportunity_id');
    }
}
