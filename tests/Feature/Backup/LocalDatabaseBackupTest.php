<?php

namespace Tests\Feature\Backup;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Backup\Application\DatabaseDumpAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class LocalDatabaseBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private SyntheticLocalBackupDumper $dumper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('framework/testing/manual-db-'.bin2hex(random_bytes(8)));
        config(['backup.local.directory' => $this->directory]);
        $this->dumper = new SyntheticLocalBackupDumper;
        $this->app->instance(DatabaseDumpAdapter::class, $this->dumper);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'manual-backup-'.uniqid(), 'label' => 'Manual backup']);
        foreach ($permissions as $key) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['label' => $key]));
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    private function admin(): User
    {
        return $this->userWith(['backup.view', 'backup.manage', 'backup.create', 'backup.download']);
    }

    public function test_requires_all_backup_permissions_and_no_get_download(): void
    {
        $this->post('/settings/backup/download')->assertRedirect('/login');
        foreach ([['backup.view'], ['backup.view', 'backup.manage'], ['backup.view', 'backup.manage', 'backup.create'], ['backup.view', 'backup.create', 'backup.download']] as $permissions) {
            $this->actingAs($this->userWith($permissions))->post('/settings/backup/download')->assertForbidden();
        }
        $this->actingAs($this->admin())->get('/settings/backup')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Backup/Index')->where('canDownloadDatabase', true));
        $this->get('/settings/backup/download')->assertStatus(405);
        self::assertSame(0, $this->dumper->calls);
    }

    public function test_download_verified_gzip_and_keep_only_three_after_four_successes(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $first = null;
        for ($i = 0; $i < 4; $i++) {
            $this->travelTo(now()->addSecond());
            $response = $this->post('/settings/backup/download');
            $response->assertOk()->assertHeader('Content-Type', 'application/gzip');
            self::assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
            self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $data = $response->streamedContent();
            self::assertSame($this->dumper->sql, gzdecode($data));
            self::assertSame(hash('sha256', $data), $response->headers->get('X-Backup-SHA256'));
            $paths = glob($this->directory.'/erp-db-*.sql.gz');
            if ($i === 0) {
                $first = $paths[0];
            }
            self::assertCount(min($i + 1, 3), $paths);
        }
        self::assertFileDoesNotExist($first);
        self::assertSame(0700, fileperms($this->directory) & 0777);
        foreach (glob($this->directory.'/erp-db-*.sql.gz') as $path) {
            self::assertSame(0600, fileperms($path) & 0777);
        }
    }

    public function test_failed_dump_or_invalid_gzip_never_removes_previous_backups(): void
    {
        $this->actingAs($this->admin());
        for ($i = 0; $i < 3; $i++) {
            $this->travelTo(now()->addSecond());
            $this->post('/settings/backup/download')->assertOk()->streamedContent();
        }
        $before = glob($this->directory.'/erp-db-*.sql.gz');
        $hashes = array_map(fn ($path) => hash_file('sha256', $path), $before);
        foreach (['throw', 'corrupt'] as $mode) {
            $this->dumper->mode = $mode;
            $this->post('/settings/backup/download')->assertStatus(503)->assertJsonStructure(['message']);
            self::assertSame($before, glob($this->directory.'/erp-db-*.sql.gz'));
            self::assertSame($hashes, array_map(fn ($path) => hash_file('sha256', $path), $before));
            self::assertSame([], glob($this->directory.'/.pending-*'));
        }
    }

    public function test_two_stage_create_lists_and_downloads_without_running_dump_again(): void
    {
        $this->actingAs($this->admin());
        $this->getJson('/settings/backup/files')->assertOk()->assertExactJson(['files' => []]);
        $created = $this->postJson('/settings/backup/create')->assertOk()->assertJsonPath('success', true);
        $filename = $created->json('backup.filename');
        self::assertSame(1, $this->dumper->calls);
        $this->getJson('/settings/backup/files')->assertOk()->assertJsonPath('files.0.filename', $filename);
        for ($i = 0; $i < 2; $i++) {
            $download = $this->get('/settings/backup/file?filename='.urlencode($filename))->assertOk();
            self::assertSame($created->json('backup.sha256'), $download->headers->get('X-Backup-SHA256'));
            self::assertSame($this->dumper->sql, gzdecode($download->streamedContent()));
        }
        self::assertSame(1, $this->dumper->calls);
        $this->get('/settings/backup/file?filename=..%2F.env')->assertNotFound();
        $this->actingAs($this->userWith(['backup.view']));
        $this->postJson('/settings/backup/create')->assertForbidden();
        $this->getJson('/settings/backup/files')->assertForbidden();
        $this->get('/settings/backup/file?filename='.urlencode($filename))->assertForbidden();
    }

    public function test_parallel_backup_is_rejected_without_running_dumper(): void
    {
        mkdir($this->directory, 0700, true);
        $lock = fopen($this->directory.'/.backup.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $this->actingAs($this->admin())->post('/settings/backup/download')->assertStatus(503);
            self::assertSame(0, $this->dumper->calls);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

final class SyntheticLocalBackupDumper extends DatabaseDumpAdapter
{
    public int $calls = 0;

    public string $mode = 'ok';

    public string $sql = "CREATE TABLE sample (id INTEGER PRIMARY KEY);\nINSERT INTO sample VALUES (1);\n";

    public function dump(string $destination): void
    {
        $this->calls++;
        if ($this->mode === 'throw') {
            throw new \RuntimeException('synthetic dump failure');
        }
        file_put_contents($destination, $this->mode === 'corrupt' ? str_repeat('invalid', 12) : gzencode($this->sql));
    }
}
