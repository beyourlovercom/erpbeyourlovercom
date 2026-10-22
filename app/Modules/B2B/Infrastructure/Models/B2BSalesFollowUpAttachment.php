<?php

namespace App\Modules\B2B\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class B2BSalesFollowUpAttachment extends Model
{
    protected $table = 'b2b_sales_followup_attachments';
    protected $guarded = ['id'];
    public function followUp(): BelongsTo
    {
        return $this->belongsTo(B2BSalesFollowUp::class, 'follow_up_id');
    }
}
