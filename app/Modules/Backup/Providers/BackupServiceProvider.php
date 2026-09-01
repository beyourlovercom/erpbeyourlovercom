<?php

namespace App\Modules\Backup\Providers;

use App\Modules\Backup\Application\Contracts\BackupRepository;
use App\Modules\Backup\Application\Contracts\GithubReleaseGateway;
use App\Modules\Backup\Infrastructure\EloquentBackupRepository;
use App\Modules\Backup\Infrastructure\HttpGithubReleaseGateway;
use Illuminate\Support\ServiceProvider;

final class BackupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(BackupRepository::class, EloquentBackupRepository::class);
        $this->app->bind(GithubReleaseGateway::class, HttpGithubReleaseGateway::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');
    }
}
