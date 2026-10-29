<?php

namespace App\Modules\Logistics\Infrastructure\Xlwms;

use Illuminate\Database\Eloquent\Model;

final class XlwmsDispatchAttempt extends Model
{
    protected $table = 'xlwms_dispatch_attempts';
    protected $guarded = ['id'];
}
