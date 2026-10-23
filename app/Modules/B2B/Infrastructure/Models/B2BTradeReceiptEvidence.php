<?php

namespace App\Modules\B2B\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class B2BTradeReceiptEvidence extends Model
{
    protected $table='b2b_trade_receipt_evidence';
    protected $guarded=['id'];
    protected function casts():array
    {
        return ['claimed_amount_micros'=>'integer','claimed_paid_on'=>'date','reviewed_at'=>'datetime'];
    }
    public function tradeOrder():BelongsTo {return $this->belongsTo(B2BTradeOrder::class,'trade_order_id');}
}
