<?php

namespace App\Modules\Identity\Providers;

use App\Modules\Identity\Experience\Fortify\IdentityFortify;
use Illuminate\Support\ServiceProvider;

final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');
        IdentityFortify::boot();
    }
}
