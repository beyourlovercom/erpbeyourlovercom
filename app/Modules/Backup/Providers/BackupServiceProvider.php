<?php

namespace App\Modules\Backup\Providers;

use App\Modules\Backup\Application\Contracts\BackupRepository;
use App\Modules\Backup\Application\Contracts\GithubReleaseGateway;
use App\Modules\Backup\Application\Contracts\RestoreCompatibility;
use App\Modules\Backup\Application\Contracts\RestoreEncryptor;
use App\Modules\Backup\Application\Contracts\RestorePackageExtractor;
use App\Modules\Backup\Application\StreamingAesGcmEncryptor;
use App\Modules\Backup\Infrastructure\CurrentRestoreCompatibility;
use App\Modules\Backup\Infrastructure\EloquentBackupRepository;
use App\Modules\Backup\Infrastructure\HttpGithubReleaseGateway;
use App\Modules\Backup\Infrastructure\ZstdTarRestorePackageExtractor;
use Illuminate\Support\ServiceProvider;

final class BackupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(BackupRepository::class, EloquentBackupRepository::class);
        $this->app->bind(GithubReleaseGateway::class, HttpGithubReleaseGateway::class);
        $this->app->bind(RestoreEncryptor::class, StreamingAesGcmEncryptor::class);
        $this->app->bind(RestorePackageExtractor::class, ZstdTarRestorePackageExtractor::class);
        $this->app->bind(RestoreCompatibility::class, CurrentRestoreCompatibility::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');
    }
}
