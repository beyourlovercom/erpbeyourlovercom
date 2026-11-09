<?php

namespace App\Modules\Shell\Application;

use Illuminate\Http\Request;

final class GlobalGridPilot
{
    public static function available(): bool
    {
        return app()->environment(['local', 'testing', 'development']);
    }

    public static function enabled(Request $request): bool
    {
        return self::available() && $request->query('global_grid') === '1';
    }
}
