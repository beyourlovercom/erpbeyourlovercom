<?php

namespace App\Modules\Backup\Experience\Controllers;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Backup\Application\BackupRunData;
use App\Modules\Backup\Application\BackupScheduleCadence;
use App\Modules\Backup\Application\BackupScheduleSettings;
use App\Modules\Backup\Application\Contracts\BackupRepository;
use App\Modules\Backup\Application\LocalDatabaseBackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BackupController
{
    public function __construct(
        private readonly BackupScheduleSettings $settings,
        private readonly BackupRepository $repository,
    ) {}

    public function index(Request $request): Response
    {
        $schedule = $this->settings->current();
        $time = (string) config('backup.automatic.time', '02:00');
        $timezone = (string) config('backup.automatic.timezone', 'UTC');

        return Inertia::render('Backup/Index', [
            'canDownloadDatabase' => $this->canDownload($request),
            'actions' => [
                ['key' => 'create', 'label' => '立即备份', 'available' => false],
                ['key' => 'download', 'label' => '下载备份', 'available' => false],
                ['key' => 'encrypted_download', 'label' => '加密并下载', 'available' => false],
                ['key' => 'publish_github', 'label' => '加密并发布到 GitHub', 'available' => false],
            ],
            'history' => array_map(fn (BackupRunData $run): array => $this->historyRow($run), $this->repository->recentRuns(5)),
            'storageUsage' => null,
            'policy' => [
                'format' => config('backup.format.name'),
                'includedRoots' => config('backup.storage.included_roots'),
                'workspace' => config('backup.storage.workspace'),
                'status' => '备份引擎已接入；手动操作入口暂未启用',
            ],
            'automaticBackup' => [
                'enabled' => $schedule->enabled,
                'cadence' => $schedule->cadence->value,
                'canManage' => (bool) $request->user()?->can('backup.manage'),
                'retentionCount' => (int) config('backup.automatic.retention_count', 5),
                'options' => [
                    ['value' => 'daily', 'label' => '每天', 'detail' => "每天 {$time} {$timezone}"],
                    ['value' => 'weekly', 'label' => '每周', 'detail' => "每周一 {$time} {$timezone}"],
                    ['value' => 'monthly', 'label' => '每月', 'detail' => "每月 1 日 {$time} {$timezone}"],
                ],
            ],
            'restore' => [
                'local' => ['available' => false, 'status' => '恢复基础已就绪，操作入口尚未启用'],
                'github' => ['available' => false, 'status' => 'GitHub 恢复基础已就绪，操作入口尚未启用'],
            ],
        ]);
    }

    private function canDownload(Request $request): bool
    {
        $user = $request->user();

        return $user && $user->can('backup.manage') && $user->can('backup.create') && $user->can('backup.download');
    }

    public function files(Request $request, LocalDatabaseBackupService $backup): JsonResponse
    {
        abort_unless($this->canDownload($request), 403);

        return response()->json(['files' => $backup->recent()])->header('Cache-Control', 'no-store, private');
    }

    public function create(Request $request, LocalDatabaseBackupService $backup, AuditRecorder $audit): JsonResponse
    {
        abort_unless($this->canDownload($request), 403);
        try {
            $result = $backup->create();
            fclose($result['stream']);
            $record = ['filename' => $result['filename'], 'size' => $result['size'], 'sha256' => $result['sha256']];
            $audit->record(AuditActor::user($request->user(), AuditSource::Web), 'backup.database.created', 'database_backup',
                after: [...$record, 'beijing_time' => now('Asia/Shanghai')->toIso8601String()]);

            return response()->json(['success' => true, 'backup' => $record, 'files' => $backup->recent()])->header('Cache-Control', 'no-store, private');
        } catch (\Throwable $e) {
            return response()->json(['message' => '数据库备份未确认成功，请检查备份列表。'], 503);
        }
    }

    public function file(Request $request, LocalDatabaseBackupService $backup, AuditRecorder $audit): StreamedResponse|JsonResponse
    {
        abort_unless($this->canDownload($request), 403);
        $filename = $request->query('filename');
        abort_unless(is_string($filename), 404);
        try {
            $result = $backup->open($filename);
        } catch (\Throwable $e) {
            return response()->json(['message' => '备份文件校验失败。'], 503);
        }
        abort_if($result === null, 404);
        try {
            $audit->record(AuditActor::user($request->user(), AuditSource::Web), 'backup.database.download', 'database_backup',
                after: ['filename' => $result['filename'], 'bytes' => $result['size'], 'sha256' => $result['sha256'], 'beijing_time' => now('Asia/Shanghai')->toIso8601String()]);
        } catch (\Throwable $e) {
            fclose($result['stream']);

            return response()->json(['message' => '备份审计记录失败，请联系管理员。'], 503);
        }

        return $this->stream($result);
    }

    public function download(Request $request, LocalDatabaseBackupService $backup, AuditRecorder $audit): StreamedResponse|JsonResponse
    {
        abort_unless($this->canDownload($request), 403);
        try {
            $result = $backup->create();
        } catch (\Throwable $e) {
            // Never send database configuration, SQL or subprocess output to the browser.
            return response()->json(['message' => '数据库备份失败或已有备份正在执行，请稍后重试。'], 503);
        }
        try {
            $audit->record(AuditActor::user($request->user(), AuditSource::Web), 'backup.database.download', 'database_backup',
                after: ['filename' => $result['filename'], 'bytes' => $result['size'], 'sha256' => $result['sha256'], 'beijing_time' => now('Asia/Shanghai')->toIso8601String()]);
        } catch (\Throwable $e) {
            fclose($result['stream']);

            return response()->json(['message' => '备份审计记录失败，请联系管理员。'], 503);
        }

        return $this->stream($result);
    }

    private function stream(array $result): StreamedResponse
    {
        return response()->streamDownload(static function () use ($result): void {
            try {
                fpassthru($result['stream']);
            } finally {
                fclose($result['stream']);
            }
        }, $result['filename'], [
            'Content-Type' => 'application/gzip',
            'Content-Length' => (string) $result['size'],
            'Cache-Control' => 'no-store, private, no-transform',
            'X-Content-Type-Options' => 'nosniff',
            'X-Backup-SHA256' => $result['sha256'],
        ]);
    }

    public function updateAutomaticBackup(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'cadence' => ['required', 'string', 'in:daily,weekly,monthly'],
        ]);

        $this->settings->update(
            (bool) $validated['enabled'],
            BackupScheduleCadence::from($validated['cadence']),
        );

        return redirect()->route('backup.index')->with('success', '自动备份设置已保存。');
    }

    private function historyRow(BackupRunData $run): array
    {
        return [
            'id' => $run->id,
            'runUuid' => $run->runUuid,
            'type' => $run->backupType->value,
            'state' => $run->state->value,
            'source' => ($run->metadata['origin'] ?? null) === 'scheduled' ? '自动备份' : '手动/系统',
            'cadence' => $run->metadata['cadence'] ?? null,
            'createdAt' => $run->createdAt?->toIso8601String(),
        ];
    }
}
