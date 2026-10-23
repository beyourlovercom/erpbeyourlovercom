<?php

namespace App\Modules\Finance\Infrastructure\Models;

use App\Modules\B2B\Infrastructure\Models\B2BTradeOrder;
use App\Modules\B2B\Infrastructure\Models\B2BTradeReceiptEvidence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FinanceB2BTradeCashEvent extends Model
{
    /**
     * Settled Finance events are append-only at the application layer. A refund
     * must be a distinct negative cash event; no in-place corrections/deletes.
     */
    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new \LogicException('Finance B2B cash events are append-only; use an adjustment event.');
        });
        static::deleting(static function (): never {
            throw new \LogicException('Finance B2B cash events cannot be deleted.');
        });
    }

    protected $table='finance_b2b_trade_cash_events';
    protected $guarded=['id'];
    protected function casts(): array
    {
        return ['amount_micros'=>'integer','value_date'=>'date','confirmed_at'=>'datetime'];
    }
    public function tradeOrder(): BelongsTo
    {
        return $this->belongsTo(B2BTradeOrder::class,'trade_order_id');
    }
    public function evidence(): BelongsTo
    {
        return $this->belongsTo(B2BTradeReceiptEvidence::class,'receipt_evidence_id');
    }
}
