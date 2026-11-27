<?php

namespace App\Modules\Orders\Application;

use Illuminate\Contracts\Auth\Authenticatable;

/** Scope only workflow and warehouse commands; never grants generic order editing. */
final class OrderLogisticsAuthority
{
    public static function allows(?Authenticatable $actor): bool
    {
        return (bool) ($actor?->can('orders.edit') || $actor?->can('logistics.operate'));
    }
}
