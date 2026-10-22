<?php

namespace App\Modules\B2B\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class B2BTradeAuditEvent extends Model
{
    protected $table='b2b_trade_audit_events';
    protected $guarded=['id'];
    protected function casts():array
    {
        return ['context'=>'array','occurred_at'=>'datetime'];
    }
    public function tradeOrder():BelongsTo {return $this->belongsTo(B2BTradeOrder::class,'trade_order_id');}
}
