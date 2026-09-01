<?php

namespace App\Modules\Backup\Experience\Controllers;

use Inertia\Inertia;
use Inertia\Response;

final class BackupController
{
    public function index(): Response
    {
        return Inertia::render('Backup/Index', [
            'actions' => [
                ['key' => 'create', 'label' => '立即备份', 'available' => false],
                ['key' => 'download', 'label' => '下载备份', 'available' => false],
                ['key' => 'encrypted_download', 'label' => '加密并下载', 'available' => false],
                ['key' => 'publish_github', 'label' => '加密并发布到 GitHub', 'available' => false],
            ],
            'history' => [],
            'storageUsage' => null,
            'policy' => [
                'format' => config('backup.format.name'),
                'includedRoots' => config('backup.storage.included_roots'),
                'workspace' => config('backup.storage.workspace'),
                'status' => '待 M1.2 引擎接入',
            ],
            'restore' => [
                'local' => ['available' => false, 'status' => '待 M1.4 恢复功能接入'],
                'github' => ['available' => false, 'status' => '待 M1.3/M1.4 接入'],
            ],
        ]);
    }
}
