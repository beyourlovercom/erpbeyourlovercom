<?php

namespace App\Modules\Logistics\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

final class WarehouseDispatchState extends Model
{
    protected $guarded = ['id'];
    protected $table = 'warehouse_dispatch_states';
    protected $casts = ['last_checked_at' => 'datetime'];
}
