<?php

namespace App\Modules\Logistics\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

final class Carrier extends Model
{
    protected $table = 'logistics_carriers';

    protected $fillable = ['name', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
