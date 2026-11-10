<?php

namespace App\Modules\Shell\Application;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class GlobalGridPilot
{
    public static function available(): bool
    {
        // Production: existing ERP Owner ID 1 must explicitly opt in.
        // All other accounts and default routes continue using legacy tables.
        return app()->environment(['local', 'testing', 'development'])
            || (app()->environment('production') && Auth::id() === 1);
    }

    public static function enabled(Request $request): bool
    {
        return self::available() && $request->query('global_grid') === '1';
    }
}
