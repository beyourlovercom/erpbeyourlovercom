<?php

namespace Tests\Architecture;

use App\Modules\ModuleManifest;
use App\Modules\ModulesServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\TestCase;

final class ModuleArchitectureTest extends TestCase
{
    private const BASELINE = '7e1f0a7dbaa2b686be552c3683680121fa6f09c5';
    private const BUILDER_IDENTITY = 'xshoow-builder / openai-api / openai-api/gpt-5.6-luna';

    private const MODULE_FILES = [
        'app/Modules/ModuleManifest.php',
        'app/Modules/ModulesServiceProvider.php',
    ];

    private const SUPPORT_FILES = [
        'app/Support/Audit/AuditActor.php', 'app/Support/Audit/AuditService.php',
        'app/Support/Audit/AuditSource.php', 'app/Support/Audit/AuditWriteException.php',
        'app/Support/Audit/AuditWriter.php', 'app/Support/Audit/EloquentAuditWriter.php',
        'app/Support/Audit/ImmutableAuditBuilder.php', 'app/Support/AuthorizationKeys.php',
        'app/Support/DataScope.php', 'app/Support/ErpShellNavigation.php',
        'app/Support/LegacyErpNavigationPaths.php', 'app/Support/Runtime/QueueJobConvention.php',
        'app/Support/Runtime/QueueName.php', 'app/Support/Runtime/RedisClientCapability.php',
        'app/Support/Runtime/RedisRuntimeProbe.php', 'app/Support/Runtime/RuntimeHealthService.php',
        'app/Support/Sync/SyncOutboxService.php', 'app/Support/Sync/SyncOutboxStatus.php',
        'app/Support/Sync/SyncOutboxWriteException.php',
    ];

    private const MIGRATIONS = [
        'database/migrations/0001_01_01_000000_create_users_table.php' => '10d79883bc08ce46510f9ad8b1830b4d06d586dc56ae2c8bac3b3e491f69a90f',
        'database/migrations/0001_01_01_000001_create_cache_table.php' => 'fbb4665e5a977e71df4e74ec75b3c06ca4f17ad0de7138fe9786639189978e77',
        'database/migrations/0001_01_01_000002_create_jobs_table.php' => 'df4910687180313a14a3f96a8bddd808ae145ed1b107cd772caf865c0dc2b746',
        'database/migrations/2026_07_31_040000_create_roles_permissions_tables.php' => 'f7ec168bc3d73ddec6d320c18baf14abfd220bde52e717cc67eecc12aa7bc3c5',
        'database/migrations/2026_07_31_040001_add_is_active_to_users_table.php' => 'abcf4fdf4ba1f3e799acf915e3dc455f8365ce595bd254ee484eec249c0b40fd',
        'database/migrations/2026_07_31_040002_create_audit_records_table.php' => '9646245c3f3be220a760546d3fdf54f9656118b644104bd8e9177122e304fcb0',
        'database/migrations/2026_08_01_000000_create_sync_outbox_table.php' => '1404b68d7e91f3c0c7762293dae0442c1da3a9e4ae889294cf00d5cbf61b3ee1',
    ];

    private const ROUTE_NAMES = ['root', 'login', 'dashboard', 'system.runtime.health', 'system.audit-records.index', 'architecture.foundation', 'kol.orders', 'kol.orders.show'];
    private const PERMISSION_KEYS = ['dashboard.view', 'prototype.view', 'architecture.foundation.view', 'audit.view', 'system.permissions.manage', 'logistics.view', 'sync.view', 'customers.view', 'kol.view', 'inventory.view', 'procurement.view', 'products.view', 'guides.view', 'accounting.view'];
    private const FRONTEND_FILES = [
        'resources/js/Components/AppShell/GlobalNavigation.test.ts', 'resources/js/Components/AppShell/GlobalNavigation.vue',
        'resources/js/Components/AppShell/ModuleSidebar.vue', 'resources/js/Components/AppShell/TopBar.vue',
        'resources/js/Components/AppShell/UserAccountMenu.test.ts', 'resources/js/Components/AppShell/UserAccountMenu.vue',
        'resources/js/Components/UI/EmptyState.test.ts', 'resources/js/Components/UI/EmptyState.vue',
        'resources/js/Components/UI/FormField.test.ts', 'resources/js/Components/UI/FormField.vue',
        'resources/js/Components/UI/TextInput.test.ts', 'resources/js/Components/UI/TextInput.vue',
        'resources/js/Components/UI/UiAlert.test.ts', 'resources/js/Components/UI/UiAlert.vue',
        'resources/js/Components/UI/UiButton.test.ts', 'resources/js/Components/UI/UiButton.vue',
        'resources/js/Layouts/AuthenticatedLayout.test.ts', 'resources/js/Layouts/AuthenticatedLayout.vue',
        'resources/js/Pages/Architecture/Foundation.test.ts', 'resources/js/Pages/Architecture/Foundation.vue',
        'resources/js/Pages/Auth/Login.test.ts', 'resources/js/Pages/Auth/Login.vue',
        'resources/js/Pages/Dashboard/Index.test.ts', 'resources/js/Pages/Dashboard/Index.vue',
        'resources/js/Pages/Shell/Placeholder.test.ts', 'resources/js/Pages/Shell/Placeholder.vue',
        'resources/js/app.js', 'resources/js/app.ts', 'resources/js/types/navigation.ts',
    ];

    public function test_fixed_baseline_is_pinned_to_authorized_base(): void
    {
        self::assertSame('7e1f0a7dbaa2b686be552c3683680121fa6f09c5', self::BASELINE);
        self::assertSame('xshoow-builder / openai-api / openai-api/gpt-5.6-luna', self::BUILDER_IDENTITY);
        self::assertSame([], $this->subdirectoriesUnder($this->path('app/Modules')));
        $this->assertFixedFileSet(self::MODULE_FILES, 'MOD-001', 'app/Modules');
        $this->assertFixedFileSet(self::SUPPORT_FILES, 'SUPPORT-001', 'app/Support');
        $this->assertFixedFileSet(array_keys(self::MIGRATIONS), 'MIG-001', 'database/migrations');
        $this->assertFixedFileSet(self::FRONTEND_FILES, 'FE-001', 'resources/js');
        foreach (self::MIGRATIONS as $path => $hash) {
            self::assertFileExists($this->path($path));
            self::assertSame($hash, hash_file('sha256', $this->path($path)));
        }
        self::assertSame('bd2da50aaf6178abed7feb11016cf28d731996f53c3260f9a4a7654aed3fbffd', hash_file('sha256', $this->path('menu/APPROVED_MENU.json')));
    }

    public function test_boundary_rules_and_compatibility_contracts(): void
    {
        self::assertDirectoryDoesNotExist($this->path('app/Shared'));
        self::assertSame([], $this->tokenizerViolations('DOM-001', 'app/Domain', ['Illuminate\\', 'Facade', 'Controller', 'Request', 'Route', 'Inertia', 'Eloquent']));
        self::assertSame([], $this->tokenizerViolations('APP-001', 'app/Application', ['app\\Modules\\', 'private']));
        self::assertSame([], $this->tokenizerViolations('INF-001', 'app/Infrastructure', ['app\\Application\\', 'app\\Domain\\']));
        self::assertSame([], $this->tokenizerViolations('SHARED-001', 'app/Shared', ['Illuminate\\', 'Eloquent', 'Route', 'app\\Modules\\']));
        $providers = require $this->path('bootstrap/providers.php');
        self::assertSame([AppServiceProvider::class, ModulesServiceProvider::class, FortifyServiceProvider::class], $providers);
        self::assertSame([], ModuleManifest::providers());
        self::assertSame(ServiceProvider::class, get_parent_class(ModulesServiceProvider::class));
        $appProvider = file_get_contents($this->path('app/Providers/AppServiceProvider.php'));
        self::assertIsString($appProvider);
        foreach (['AuditWriter::class', 'Gate::before', 'Gate::define', 'AuthorizationKeys::cases'] as $evidence) {
            self::assertStringContainsString($evidence, $appProvider);
        }
        $routes = file_get_contents($this->path('routes/web.php'));
        self::assertIsString($routes);
        preg_match_all('/->name\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/', $routes, $routeMatches);
        self::assertSame(self::ROUTE_NAMES, $routeMatches[1]);
        $authorizationKeys = file_get_contents($this->path('app/Support/AuthorizationKeys.php'));
        self::assertIsString($authorizationKeys);
        preg_match_all('/case\s+\w+\s*=\s*[\'\"]([^\'\"]+)[\'\"]/', $authorizationKeys, $permissionMatches);
        self::assertSame(self::PERMISSION_KEYS, array_values(array_filter($permissionMatches[1], static fn (string $key): bool => str_contains($key, '.'))));
        self::assertSame('bd2da50aaf6178abed7feb11016cf28d731996f53c3260f9a4a7654aed3fbffd', hash_file('sha256', $this->path('menu/APPROVED_MENU.json')));
    }

    public function test_all_rules_have_structured_tokenizer_records_and_limits(): void
    {
        $ruleIds = ['MOD-001', 'DOM-001', 'APP-001', 'INF-001', 'PROV-001', 'SHARED-001', 'SUPPORT-001', 'MIG-001', 'COMPAT-001', 'FE-001'];
        self::assertSame($ruleIds, array_keys(array_flip($ruleIds)));
        foreach ($this->tokenizerViolations('PROV-001', 'app/Modules', ['Illuminate\\Support\\Facades', 'Route', 'Migration', 'View', 'Event', 'Queue', 'Schedule', 'Redis']) as $record) {
            self::assertSame(['RULE_ID', 'FILE', 'LINE', 'SYMBOL_OR_DEPENDENCY', 'REASON'], array_keys($record));
        }
        foreach ($this->tokenizerRuntimeLimitations() as $record) {
            self::assertSame('UNVERIFIED', $record['RULE_ID']);
            self::assertSame('UNVERIFIED', $record['REASON']);
        }
        $fixture = <<<'PHP'
<?php
namespace Fixture\Domain;
use Vendor\Package\{Allowed, Nested\Dependency as Alias};
use \Fully\Qualified\Dependency;
#[AttributeDependency]
final class Fixture
{
    // Route::fake()
    private string $value = 'Route::fake()';
}
PHP;
        self::assertSame([], $this->tokenizerSourceViolations('DOM-001', 'memory-fixture.php', $fixture, ['Route', 'Illuminate\\']));
        $violation = $this->tokenizerSourceViolations('DOM-001', 'memory-fixture.php', "<?php\nuse Illuminate\\Support\\Facades\\Route;", ['Illuminate\\']);
        self::assertCount(1, $violation);
        self::assertSame(['RULE_ID', 'FILE', 'LINE', 'SYMBOL_OR_DEPENDENCY', 'REASON'], array_keys($violation[0]));
    }

    /** @param list<string> $expected */
    private function assertFixedFileSet(array $expected, string $ruleId, string $directory): void
    {
        foreach ($expected as $path) {
            self::assertFileExists($this->path($path));
        }
        self::assertSame($expected, $this->filesUnder($this->path($directory)), $ruleId.' baseline file set changed');
    }

    /** @return list<string> */
    private function filesUnder(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = str_replace($this->path().'/', '', $file->getPathname());
            }
        }
        sort($files);
        return $files;
    }

    /** @return list<string> */
    private function subdirectoriesUnder(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }
        $directories = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            if ($file->isDir() && $file->getPathname() !== $directory) {
                $directories[] = str_replace($this->path().'/', '', $file->getPathname());
            }
        }
        sort($directories);
        return $directories;
    }

    /** @param list<string> $forbidden */
    private function tokenizerViolations(string $ruleId, string $directory, array $forbidden): array
    {
        $records = [];
        foreach ($this->filesUnder($this->path($directory)) as $file) {
            $records = [...$records, ...$this->tokenizerSourceViolations($ruleId, $file, (string) file_get_contents($this->path($file)), $forbidden)];
        }
        return $records;
    }

    /** @param list<string> $forbidden */
    private function tokenizerSourceViolations(string $ruleId, string $file, string $source, array $forbidden): array
    {
        $records = [];
        foreach (token_get_all($source) as $token) {
            if (! is_array($token) || in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            $symbol = $this->tokenSymbol($token);
            foreach ($forbidden as $dependency) {
                if ($symbol !== null && str_contains($symbol, $dependency)) {
                    $records[] = ['RULE_ID' => $ruleId, 'FILE' => $file, 'LINE' => $token[2], 'SYMBOL_OR_DEPENDENCY' => $symbol, 'REASON' => 'forbidden namespace/use/FQN/group-use/attribute dependency'];
                }
            }
        }
        return $records;
    }

    private function tokenSymbol(array $token): ?string
    {
        $nameTokens = [T_NAMESPACE, T_USE, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING];
        if (defined('T_NAME_RELATIVE')) {
            $nameTokens[] = T_NAME_RELATIVE;
        }
        if (in_array($token[0], $nameTokens, true)) {
            return trim($token[1], "\\ \t\r\n");
        }
        return $token[0] === T_ATTRIBUTE ? '#[' : null;
    }

    /** @return list<array{RULE_ID:string,FILE:string,LINE:int,SYMBOL_OR_DEPENDENCY:string,REASON:string}> */
    private function tokenizerRuntimeLimitations(): array
    {
        return [
            ['RULE_ID' => 'UNVERIFIED', 'FILE' => 'runtime', 'LINE' => 0, 'SYMBOL_OR_DEPENDENCY' => 'dynamic strings/container resolution', 'REASON' => 'UNVERIFIED'],
            ['RULE_ID' => 'UNVERIFIED', 'FILE' => 'runtime', 'LINE' => 0, 'SYMBOL_OR_DEPENDENCY' => 'dynamic Provider behavior/reflection side effects', 'REASON' => 'UNVERIFIED'],
        ];
    }

    private function path(string $relative = ''): string
    {
        return dirname(__DIR__, 2).($relative === '' ? '' : '/'.$relative);
    }
}
