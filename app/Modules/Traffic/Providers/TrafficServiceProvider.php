<?php

namespace App\Modules\Traffic\Providers;

use App\Modules\Traffic\Application\Contracts\TrafficRepository;
use App\Modules\Traffic\Infrastructure\Repositories\EloquentTrafficRepository;
use Illuminate\Support\ServiceProvider;

final class TrafficServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TrafficRepository::class, EloquentTrafficRepository::class);
    }

    public function boot(): void {}
}
