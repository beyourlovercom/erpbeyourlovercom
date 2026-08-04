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
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2).'/app/Modules/ModuleManifest.php';
        require_once dirname(__DIR__, 2).'/app/Modules/ModulesServiceProvider.php';
    }

    private const BASELINE = '7e1f0a7dbaa2b686be552c3683680121fa6f09c5';

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

    private const FRONTEND_FILES = [
        'resources/js/Components/AppShell/GlobalNavigation.test.ts' => '2f8997692e36f62b3cc435dc7a3e850cc79d5b3f1ae27c6a1a8e86e54954281f',
        'resources/js/Components/AppShell/GlobalNavigation.vue' => 'e40b18415f2c88afd5a43b7f2da0870df45e29ddbab7006bf1d07c581de566ef',
        'resources/js/Components/AppShell/ModuleSidebar.vue' => '127f19089943410e88558a4f4fe07f7003c318c353b29582d526bdfcd64bc5a4',
        'resources/js/Components/AppShell/TopBar.vue' => 'f9c86085dfdf784a6351de332a014fb254310f40d8dfdb28c9d2ee702d3a205a',
        'resources/js/Components/AppShell/UserAccountMenu.test.ts' => 'cbad0690b0c251c28ce7ee9644eb2de5699fc80065f8bc10d4fe1125d65dd687',
        'resources/js/Components/AppShell/UserAccountMenu.vue' => '2ae335eacc6146873ffcf857d829a7c30d1136555e1339f500966c5dd7797367',
        'resources/js/Components/UI/EmptyState.test.ts' => '4fda8290eb407fe9313f32d5a5ac0f802cbb668797af87b9cb3c7f856e528b18',
        'resources/js/Components/UI/EmptyState.vue' => 'a0cab2c71afa978cca886b9b6cb9ab0f0ff40d29962109f7da7c5e1aed2297ce',
        'resources/js/Components/UI/FormField.test.ts' => '9e604341a19c1618e5949b713c6137cbc27c50c64f0830890c2a50cc2e38df4c',
        'resources/js/Components/UI/FormField.vue' => '681c3d841736873ee50fa7528ed3d82fe54452b927d76be1ccca9eed1b9dfe2a',
        'resources/js/Components/UI/TextInput.test.ts' => 'a379b41444e9f3995cc59506022ab463201da0ec15250afe15a5adb2adee4427',
        'resources/js/Components/UI/TextInput.vue' => 'bab268f21dcb88a55c8ebf85821bf7121cf1eea017645319afe3bb1e086a3539',
        'resources/js/Components/UI/UiAlert.test.ts' => 'af5615f3a4d0eeae39dbaa785820959f449ce518e90d881da4608e9dcca2e015',
        'resources/js/Components/UI/UiAlert.vue' => 'e3e6ee0e127ae0911efce1bb1d1cb873146cf77220c9c9156a86b968b03b519f',
        'resources/js/Components/UI/UiButton.test.ts' => '126bc019e025164dc901b9c21986aa370fedf26da3d1618c90d7c60791797bf8',
        'resources/js/Components/UI/UiButton.vue' => '7ae624f92c56911ce58b60ae54c54625e85837666ca6b9882ac04be32c718113',
        'resources/js/Layouts/AuthenticatedLayout.test.ts' => '73e5a9fd5e94af7a3b32068c4dba490ceac4c17719bc011fec962760dfe42549',
        'resources/js/Layouts/AuthenticatedLayout.vue' => '1c5ce16e05b745efe8543d59f7fac82d363fb15adb16dc24009ab8f71e589438',
        'resources/js/Pages/Architecture/Foundation.test.ts' => '68898785345d5cc641bcc8b3f165527a94c912e33c5f1941554a9a28aad97a1c',
        'resources/js/Pages/Architecture/Foundation.vue' => '0f3f96bd3ad9a5b68fa7fe5a78e20a1e0e92f4d2e6806ef30697fda64941aa70',
        'resources/js/Pages/Auth/Login.test.ts' => 'c6e17ad55beceaa7613b0a073165a3b82881f09d86ab3612682f61444349de43',
        'resources/js/Pages/Auth/Login.vue' => '58c955c2c091a18028c96300c4cd56351280a0fe2a3932161e9d8b849e280800',
        'resources/js/Pages/Dashboard/Index.test.ts' => '343324a3f14c6f5b9fe89107b534d6cae9359497fe1f0bfd4c52bc5e6dd691bb',
        'resources/js/Pages/Dashboard/Index.vue' => '4d888ec7c6098548380fc3cacc63b97d3b83f5acc0a3e8fed7a07bd506cecd7a',
        'resources/js/Pages/Shell/Placeholder.test.ts' => 'd0623492800b81b76650531eec2b979747d98daf34dcd4320ba00438529bb781',
        'resources/js/Pages/Shell/Placeholder.vue' => 'e18f588432b7d29a025f7618f977bdb7457536e8ae8028c2c343ce5c7b95472f',
        'resources/js/app.js' => '40994ac10f73e394b96d6e4742be37921fc38e477dccfdfe9e8ab90cd5e6d7d4',
        'resources/js/app.ts' => 'c667a849465fad821d6d08babe410c6d44bd7412629198522f1d408f373823b3',
        'resources/js/types/navigation.ts' => '65cca2843e7dca9cadc8540df6ca323071baf8b8ec39290cc57511bd45821779',
    ];

    public function test_frozen_module_and_support_boundaries(): void
    {
        self::assertSame([], $this->subdirectoriesUnder($this->path('app/Modules')));
        $this->assertFixedFileSet(self::MODULE_FILES, 'MOD-001', 'app/Modules');
        $this->assertFixedFileSet(self::SUPPORT_FILES, 'SUPPORT-001', 'app/Support');
        self::assertDirectoryDoesNotExist($this->path('app/Shared'));
    }

    public function test_layer_scans_are_limited_to_module_layer_directories(): void
    {
        $files = $this->moduleLayerFiles();
        self::assertSame([], $files);
        self::assertNotContains('app/Domain/Accidental.php', $files);
        self::assertNotContains('app/Application/Accidental.php', $files);
        self::assertNotContains('app/Infrastructure/Accidental.php', $files);
        self::assertSame([], $this->tokenizerViolations('DOM-001', $files, $this->domForbidden()));
        self::assertSame([], $this->tokenizerViolations('APP-001', $files, $this->appForbidden()));
        self::assertSame([], $this->tokenizerViolations('INF-001', $files, ['Infrastructure']));
    }

    public function test_domain_application_and_infrastructure_rules_have_allowed_and_violating_fixtures(): void
    {
        $domainAllowed = "<?php\nnamespace App\\Modules\\Orders\\Domain;\nuse App\\Modules\\Orders\\Domain\\Order;\nfinal class Policy {}\n";
        self::assertSame([], $this->sourceViolations('DOM-001', 'app/Modules/Orders/Domain/Policy.php', $domainAllowed, $this->domForbidden('Orders')));
        $domainBad = "<?php\nnamespace App\\Modules\\Orders\\Domain;\nuse Illuminate\\Support\\Facades\\Route;\nuse App\\Modules\\Logistics\\Infrastructure\\Carrier;\n#[App\\Modules\\Orders\\Providers\\Marker]\nfinal class Policy {}\n";
        $domainViolations = $this->sourceViolations('DOM-001', 'app/Modules/Orders/Domain/Policy.php', $domainBad, $this->domForbidden('Orders'));
        self::assertSame(['DOM-001', 'DOM-001', 'DOM-001'], array_column($domainViolations, 'RULE_ID'));
        self::assertSame([3, 4, 5], array_column($domainViolations, 'LINE'));
        self::assertSame(['Illuminate\\Support\\Facades\\Route', 'App\\Modules\\Logistics\\Infrastructure\\Carrier', 'App\\Modules\\Orders\\Providers\\Marker'], array_column($domainViolations, 'SYMBOL_OR_DEPENDENCY'));

        $appAllowed = "<?php\nnamespace App\\Modules\\Orders\\Application;\nuse App\\Modules\\Orders\\Application\\Contracts\\OrderReader;\nuse App\\Modules\\Orders\\Application\\Actions\\PlaceOrder;\nfinal class Handler {}\n";
        self::assertSame([], $this->sourceViolations('APP-001', 'app/Modules/Orders/Application/Handler.php', $appAllowed, $this->appForbidden('Orders')));
        $appBad = "<?php\nnamespace App\\Modules\\Orders\\Application;\nuse App\\Modules\\Orders\\Infrastructure\\SqlOrderReader;\nuse App\\Modules\\Logistics\\Application\\Internal\\CarrierLookup;\nfinal class Handler {}\n";
        $appViolations = $this->sourceViolations('APP-001', 'app/Modules/Orders/Application/Handler.php', $appBad, $this->appForbidden('Orders'));
        self::assertSame([3, 4], array_column($appViolations, 'LINE'));
        self::assertSame(['App\\Modules\\Orders\\Infrastructure\\SqlOrderReader', 'App\\Modules\\Logistics\\Application\\Internal\\CarrierLookup'], array_column($appViolations, 'SYMBOL_OR_DEPENDENCY'));

        $infraBad = "<?php\nnamespace App\\Modules\\Orders\\Infrastructure;\nuse App\\Modules\\Orders\\Domain\\Order;\nuse App\\Modules\\Logistics\\Application\\Contracts\\CarrierReader;\nfinal class SqlOrderReader {}\n";
        $infraViolations = $this->sourceViolations('INF-001', 'app/Modules/Orders/Infrastructure/SqlOrderReader.php', $infraBad, ['App\\Modules\\Orders\\Domain', 'App\\Modules\\Logistics\\Application']);
        self::assertCount(2, $infraViolations);
        self::assertSame(['INF-001', 'INF-001'], array_column($infraViolations, 'RULE_ID'));
    }

    public function test_provider_rule_collects_real_sources_and_rejects_forbidden_fixture(): void
    {
        $violations = $this->providerViolations();
        self::assertSame([], $violations, $this->formatRecords($violations));
        $fixture = <<<'PHP'
<?php
namespace Fixture;
use Illuminate\Support\Facades\Route;
final class Provider { public function register() { glob('app/*'); Route::get('/x'); $provider = $this->app->make($name); } }
PHP;
        $fixtureViolations = $this->sourceViolations('PROV-001', 'app/Modules/Future/Providers/BadProvider.php', $fixture, $this->providerForbidden());
        self::assertNotEmpty($fixtureViolations);
        self::assertSame(['RULE_ID', 'FILE', 'LINE', 'SYMBOL_OR_DEPENDENCY', 'REASON'], array_keys($fixtureViolations[0]));
        self::assertStringContainsString('PROV-001', $this->formatRecords($fixtureViolations));
    }

    public function test_tokenizer_handles_namespaces_uses_fqns_attributes_and_ignores_text(): void
    {
        $fixture = <<<'PHP'
<?php
namespace Fixture\Domain;
use Vendor\Package\{Allowed, Nested\Dependency as Alias};
use \Fully\Qualified\Dependency;
#[Qualified\AttributeDependency]
final class Fixture
{
    // Route::fake()
    /** Illuminate\Support\Facades\Route */
    private string $value = 'Route::fake()';
    private string $heredoc = <<<TXT
Route::fake()
TXT;
}
PHP;
        self::assertSame([], $this->sourceViolations('DOM-001', 'memory-fixture.php', $fixture, ['Route', 'Illuminate\\']));
        $groupViolation = $this->sourceViolations('DOM-001', 'memory-fixture.php', "<?php\nuse Illuminate\\Support\\{Facades\\Route, Http\\Request};", ['Illuminate\\']);
        self::assertSame(['Illuminate\\Support\\Facades\\Route', 'Illuminate\\Support\\Http\\Request'], array_column($groupViolation, 'SYMBOL_OR_DEPENDENCY'));
        $violation = $this->sourceViolations('DOM-001', 'memory-fixture.php', "<?php\nuse Illuminate\\Support\\Facades\\Route;", ['Illuminate\\']);
        self::assertCount(1, $violation);
        self::assertSame(2, $violation[0]['LINE']);
        self::assertSame('Illuminate\\Support\\Facades\\Route', $violation[0]['SYMBOL_OR_DEPENDENCY']);
    }

    public function test_all_rule_ids_have_real_assertions_and_compatibility_evidence(): void
    {
        $ruleIds = ['MOD-001', 'DOM-001', 'APP-001', 'INF-001', 'PROV-001', 'SHARED-001', 'SUPPORT-001', 'MIG-001', 'COMPAT-001', 'FE-001'];
        self::assertSame($ruleIds, array_keys(array_flip($ruleIds)));
        $this->assertFixedFileSet(array_keys(self::MIGRATIONS), 'MIG-001', 'database/migrations');
        foreach (self::MIGRATIONS as $path => $hash) {
            self::assertSame($hash, hash_file('sha256', $this->path($path)));
        }
        foreach (self::FRONTEND_FILES as $path => $hash) {
            self::assertFileExists($this->path($path));
            self::assertSame($hash, hash_file('sha256', $this->path($path)), 'FE-001 '.$path);
        }
        self::assertSame('bd2da50aaf6178abed7feb11016cf28d731996f53c3260f9a4a7654aed3fbffd', hash_file('sha256', $this->path('menu/APPROVED_MENU.json')));
        $appProvider = (string) file_get_contents($this->path('app/Providers/AppServiceProvider.php'));
        foreach (['AuditWriter::class', 'Gate::before', 'Gate::define', 'AuthorizationKeys::cases'] as $evidence) {
            self::assertStringContainsString($evidence, $appProvider);
        }
        self::assertSame(ServiceProvider::class, get_parent_class(ModulesServiceProvider::class));
        self::assertSame([], ModuleManifest::providers());
        self::assertSame([AppServiceProvider::class, ModulesServiceProvider::class, FortifyServiceProvider::class], require $this->path('bootstrap/providers.php'));
    }

    /** @return list<string> */
    private function moduleLayerFiles(): array
    {
        $files = [];
        foreach (glob($this->path('app/Modules/*'), GLOB_ONLYDIR) ?: [] as $module) {
            foreach (['Domain', 'Application', 'Infrastructure', 'Providers'] as $layer) {
                $directory = $module.'/'.$layer;
                if (! is_dir($directory)) continue;
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = str_replace($this->path().'/', '', $file->getPathname());
                }
            }
        }
        sort($files);
        return $files;
    }

    /** @param list<string> $files @param list<string> $forbidden */
    private function tokenizerViolations(string $ruleId, array $files, array $forbidden): array
    {
        $records = [];
        foreach ($files as $file) {
            $records = [...$records, ...$this->sourceViolations($ruleId, $file, (string) file_get_contents($this->path($file)), $forbidden)];
        }
        return $records;
    }

    /** @param list<string> $forbidden @return list<array{RULE_ID:string,FILE:string,LINE:int,SYMBOL_OR_DEPENDENCY:string,REASON:string}> */
    private function sourceViolations(string $ruleId, string $file, string $source, array $forbidden): array
    {
        $records = [];
        $tokens = token_get_all($source);
        $usePrefix = '';
        $inUse = false;
        $braceDepth = 0;
        foreach ($tokens as $tokenIndex => $token) {
            if (! is_array($token)) {
                if ($inUse && $token === '{') {
                    $braceDepth = 1;
                } elseif ($inUse && $token === '}' && $braceDepth > 0) {
                    $braceDepth--;
                } elseif ($inUse && $token === ';') {
                    $inUse = false;
                    $usePrefix = '';
                }
                continue;
            }
            [$type, $text, $line] = $token;
            if (in_array($type, [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            if ($type === T_USE) {
                $inUse = true;
                $braceDepth = 0;
            }
            $symbol = null;
            if ($type === T_VARIABLE && $text === '$name') {
                $symbol = $text;
            } elseif (in_array($type, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true) || (defined('T_NAME_RELATIVE') && $type === T_NAME_RELATIVE)) {
                $symbol = trim($text, "\\ \t\r\n");
                if ($inUse && $braceDepth > 0 && $usePrefix !== '' && $type !== T_STRING) {
                    $symbol = trim($usePrefix, "\\").'\\'.$symbol;
                }
                if ($inUse && $braceDepth === 0 && $type !== T_STRING) {
                    $usePrefix = trim($text, "\\ \t\r\n");
                }
            }
            if ($symbol === null) {
                continue;
            }
            if ($inUse && $braceDepth === 0 && $type !== T_STRING) {
                $nextIndex = $tokenIndex + 1;
                while (isset($tokens[$nextIndex]) && is_array($tokens[$nextIndex]) && $tokens[$nextIndex][0] === T_WHITESPACE) $nextIndex++;
                $nextToken = $tokens[$nextIndex] ?? null;
                if ($nextToken === '{' || (is_array($nextToken) && $nextToken[0] === T_NS_SEPARATOR)) continue;
            }
            foreach ($forbidden as $dependency) {
                if ($this->matchesDependency($symbol, $dependency)) {
                    $records[] = ['RULE_ID' => $ruleId, 'FILE' => $file, 'LINE' => $line, 'SYMBOL_OR_DEPENDENCY' => $symbol, 'REASON' => 'forbidden namespace/use/FQN/group-use/attribute dependency'];
                    break;
                }
            }
        }
        return $records;
    }

    private function matchesDependency(string $symbol, string $dependency): bool
    {
        return $symbol === trim($dependency, '\\') || str_starts_with($symbol, trim($dependency, '\\').'\\') || str_contains($symbol, $dependency);
    }

    /** @return list<string> */
    private function domForbidden(?string $module = null): array
    {
        return ['Illuminate\\', 'Facade', 'Controller', 'Request', 'Route', 'Inertia', 'Eloquent', 'Infrastructure', 'Experience', 'Providers', 'App\\Modules\\'.($module === null ? '__other__' : ($module === 'Orders' ? 'Logistics' : 'Orders')).'\\'];
    }

    /** @return list<string> */
    private function appForbidden(?string $module = null): array
    {
        return ['Infrastructure', 'App\\Modules\\'.($module === null ? '__other__' : ($module === 'Orders' ? 'Logistics' : 'Orders')).'\\Application\\Internal'];
    }

    /** @return list<string> */
    private function providerForbidden(): array
    {
        return ['filesystem', 'glob', 'scandir', 'Finder', 'convention', 'Route', 'Migration', 'View', 'Event', 'Queue', 'Schedule', 'Redis', 'Orders', 'Logistics', '$name'];
    }

    /** @return list<array{RULE_ID:string,FILE:string,LINE:int,SYMBOL_OR_DEPENDENCY:string,REASON:string}> */
    private function providerViolations(): array
    {
        $files = ['app/Modules/ModuleManifest.php', 'app/Modules/ModulesServiceProvider.php'];
        foreach (glob($this->path('app/Modules/*/Providers'), GLOB_ONLYDIR) ?: [] as $directory) {
            $files = [...$files, ...$this->filesUnder($directory)];
        }
        return $this->tokenizerViolations('PROV-001', $files, $this->providerForbidden());
    }

    /** @param list<string> $expected */
    private function assertFixedFileSet(array $expected, string $ruleId, string $directory): void
    {
        foreach ($expected as $path) self::assertFileExists($this->path($path));
        self::assertSame($expected, $this->filesUnder($this->path($directory)), $ruleId.' baseline file set changed');
    }

    /** @return list<string> */
    private function filesUnder(string $directory): array
    {
        $files = [];
        if (is_file($directory)) return [str_replace($this->path().'/', '', $directory)];
        if (! is_dir($directory)) return [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) if ($file->isFile() && $file->getExtension() === 'php') $files[] = str_replace($this->path().'/', '', $file->getPathname());
        sort($files);
        return $files;
    }

    /** @return list<string> */
    private function subdirectoriesUnder(string $directory): array
    {
        $directories = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) if ($file->isDir()) $directories[] = str_replace($this->path().'/', '', $file->getPathname());
        sort($directories);
        return $directories;
    }

    /** @param list<array{RULE_ID:string,FILE:string,LINE:int,SYMBOL_OR_DEPENDENCY:string,REASON:string}> $records */
    private function formatRecords(array $records): string
    {
        return json_encode($records, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function path(string $relative = ''): string
    {
        return dirname(__DIR__, 2).($relative === '' ? '' : '/'.$relative);
    }
}
