<?php

namespace App\Modules\Logistics\Infrastructure\Xlwms;

use Illuminate\Database\Eloquent\Model;

/** Server-only encrypted credential storage. Never serialize this model to Inertia. */
final class WarehouseOpenApiConnection extends Model
{
    protected $table = 'warehouse_openapi_connections';

    protected $fillable = [
        'warehouse_id', 'provider', 'app_key_encrypted', 'app_secret_encrypted',
        'oms_warehouse_code', 'last_verified_at',
    ];

    protected $hidden = ['app_key_encrypted', 'app_secret_encrypted'];

    protected $casts = [
        'app_key_encrypted' => 'encrypted',
        'app_secret_encrypted' => 'encrypted',
        'last_verified_at' => 'datetime',
    ];
}
