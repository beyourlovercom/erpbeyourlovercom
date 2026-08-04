<?php

namespace App\Providers;

use App\Modules\Identity\Application\Contracts\AuditRecorder;
use App\Support\Audit\AuditWriter;
use App\Support\Audit\AuditService;
use App\Support\Audit\EloquentAuditWriter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AuditWriter::class, EloquentAuditWriter::class);
        $this->app->bind(AuditRecorder::class, AuditService::class);
    }

    public function boot(): void
    {
    }
}
