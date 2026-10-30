<?php

namespace App\Modules\Logistics\Infrastructure\Xlwms;

use Illuminate\Database\Eloquent\Model;

final class XlwmsWebDraftAttempt extends Model
{
    protected $table = 'xlwms_web_draft_attempts';
    protected $guarded = ['id'];
}
