<?php

namespace App\Modules\Kol\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class KolPayoutRequestItem extends Model
{
    protected $table = 'kol_payout_request_items';
    protected $fillable = ['payout_request_id','referral_row_id','connection_key','referral_id','commission_amount','currency'];
    protected $casts = ['payout_request_id'=>'integer','referral_row_id'=>'integer','referral_id'=>'integer','commission_amount'=>'decimal:6'];
}
