<?php

namespace Tests\Architecture;

use App\Modules\Audit\Providers\AuditServiceProvider;
use App\Modules\Customers\Providers\CustomersServiceProvider;
use App\Modules\Dashboard\Providers\DashboardServiceProvider;
use App\Modules\Finance\Providers\FinanceServiceProvider;
use App\Modules\Identity\Providers\IdentityServiceProvider;
use App\Modules\Logistics\Providers\LogisticsServiceProvider;
use App\Modules\ModuleManifest;
use App\Modules\ModulesServiceProvider;
use App\Modules\Orders\Providers\OrdersServiceProvider;
use App\Modules\Runtime\Providers\RuntimeServiceProvider;
use App\Modules\Shell\Providers\ShellServiceProvider;
use App\Modules\Sync\Providers\SyncServiceProvider;
use App\Modules\Sync\Providers\ManualSyncServiceProvider;
use App\Providers\AppServiceProvider;
use App\Support\AuthorizationKeys;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Routing\Route;
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
        'app/Modules/Audit/Application/AuditActor.php',
        'app/Modules/Audit/Application/AuditService.php',
        'app/Modules/Audit/Application/AuditSource.php',
        'app/Modules/Audit/Application/AuditWriteException.php',
        'app/Modules/Audit/Application/Contracts/AuditRecorder.php',
        'app/Modules/Audit/Application/Contracts/AuditWriter.php',
        'app/Modules/Audit/Experience/Controllers/AuditRecordController.php',
        'app/Modules/Audit/Experience/Routes/web.php',
        'app/Modules/Audit/Infrastructure/EloquentAuditWriter.php',
        'app/Modules/Audit/Infrastructure/ImmutableAuditBuilder.php',
        'app/Modules/Audit/Infrastructure/Models/AuditRecord.php',
        'app/Modules/Audit/Providers/AuditServiceProvider.php',
        'app/Modules/Customers/Application/CompanyService.php',
        'app/Modules/Customers/Domain/CountryCodeNormalizer.php',
        'app/Modules/Customers/Domain/Exceptions/InvalidCompanyAttributeException.php',
        'app/Modules/Customers/Domain/WebsiteDomainNormalizer.php',
        'app/Modules/Customers/Experience/Controllers/CompanyController.php',
        'app/Modules/Customers/Experience/Routes/web.php',
        'app/Modules/Customers/Infrastructure/Models/Company.php',
        'app/Modules/Customers/Providers/CustomersServiceProvider.php',
        'app/Modules/Dashboard/Application/DashboardMetrics.php',
        'app/Modules/Dashboard/Experience/Controllers/DashboardController.php',
        'app/Modules/Dashboard/Experience/Routes/web.php',
        'app/Modules/Dashboard/Providers/DashboardServiceProvider.php',
        'app/Modules/Finance/Application/OrderAnalysisService.php',
        'app/Modules/Finance/Experience/Controllers/OrderAnalysisController.php',
        'app/Modules/Finance/Experience/Routes/web.php',
        'app/Modules/Finance/Infrastructure/Models/OrderCostEntry.php',
        'app/Modules/Finance/Infrastructure/Models/OrderFinancial.php',
        'app/Modules/Finance/Providers/FinanceServiceProvider.php',
        'app/Modules/Identity/Application/AuthorizationKeys.php',
        'app/Modules/Identity/Application/Contracts/AuditRecorder.php',
        'app/Modules/Identity/Application/DataScope.php',
        'app/Modules/Identity/Experience/Console/Commands/CreateErpUser.php',
        'app/Modules/Identity/Experience/Controllers/AuthController.php',
        'app/Modules/Identity/Experience/Fortify/AttemptPersistentAuthentication.php',
        'app/Modules/Identity/Experience/Fortify/IdentityFortify.php',
        'app/Modules/Identity/Experience/Middleware/ApplyLocalAdminBypass.php',
        'app/Modules/Identity/Experience/Middleware/EnsureActiveAuthenticatedUser.php',
        'app/Modules/Identity/Experience/Middleware/RequireAnyPermission.php',
        'app/Modules/Identity/Experience/Routes/web.php',
        'app/Modules/Identity/Infrastructure/Models/Permission.php',
        'app/Modules/Identity/Infrastructure/Models/Role.php',
        'app/Modules/Identity/Infrastructure/Models/User.php',
        'app/Modules/Identity/Providers/IdentityServiceProvider.php',
        'app/Modules/Logistics/Application/ShipmentService.php',
        'app/Modules/Logistics/Experience/Controllers/LogisticsController.php',
        'app/Modules/Logistics/Experience/Routes/web.php',
        'app/Modules/Logistics/Infrastructure/Models/Shipment.php',
        'app/Modules/Logistics/Providers/LogisticsServiceProvider.php',
        'app/Modules/ModuleManifest.php',
        'app/Modules/ModulesServiceProvider.php',
        'app/Modules/Runtime/Application/Contracts/RedisRuntimeProbe.php',
        'app/Modules/Runtime/Application/QueueJobConvention.php',
        'app/Modules/Runtime/Application/QueueName.php',
        'app/Modules/Runtime/Application/RedisClientCapability.php',
        'app/Modules/Runtime/Application/RuntimeHealthService.php',
        'app/Modules/Runtime/Experience/Console/Commands/RuntimeHealthCommand.php',
        'app/Modules/Runtime/Experience/Controllers/RuntimeHealthController.php',
        'app/Modules/Runtime/Experience/Jobs/RuntimeValidationJob.php',
        'app/Modules/Runtime/Experience/Routes/web.php',
        'app/Modules/Runtime/Infrastructure/RedisRuntimeProbe.php',
        'app/Modules/Runtime/Providers/RuntimeServiceProvider.php',
        'app/Modules/Orders/Application/OrderService.php',
        'app/Modules/Orders/Application/OrderSourceType.php',
        'app/Modules/Orders/Application/OrderStatus.php',
        'app/Modules/Orders/Experience/Controllers/OrderController.php',
        'app/Modules/Orders/Experience/Routes/web.php',
        'app/Modules/Orders/Infrastructure/Models/Order.php',
        'app/Modules/Orders/Infrastructure/Models/OrderAddress.php',
        'app/Modules/Orders/Infrastructure/Models/OrderExternalReference.php',
        'app/Modules/Orders/Infrastructure/Models/OrderItem.php',
        'app/Modules/Orders/Providers/OrdersServiceProvider.php',
        'app/Modules/Shell/Application/ErpShellNavigation.php',
        'app/Modules/Shell/Application/LegacyErpNavigationPaths.php',
        'app/Modules/Shell/Experience/Controllers/ErpShellController.php',
        'app/Modules/Shell/Experience/Controllers/ShellPlaceholderController.php',
        'app/Modules/Shell/Experience/Routes/web.php',
        'app/Modules/Shell/Providers/ShellServiceProvider.php',
        'app/Modules/Sync/Application/Contracts/SyncOutboxRepository.php',
        'app/Modules/Sync/Application/ManualSyncService.php',
        'app/Modules/Sync/Application/ManualSyncStatus.php',
        'app/Modules/Sync/Application/SyncOutboxService.php',
        'app/Modules/Sync/Application/SyncOutboxStatus.php',
        'app/Modules/Sync/Application/SyncOutboxWriteException.php',
        'app/Modules/Sync/Infrastructure/EloquentSyncOutboxRepository.php',
        'app/Modules/Sync/Infrastructure/Models/SyncOutbox.php',
        'app/Modules/Sync/Experience/Controllers/ManualSyncController.php',
        'app/Modules/Sync/Experience/Routes/web.php',
        'app/Modules/Sync/Infrastructure/Models/ManualSync.php',
        'app/Modules/Sync/Providers/ManualSyncServiceProvider.php',
        'app/Modules/Sync/Providers/SyncServiceProvider.php',
    ];

    private const MODULE_DIRECTORIES = [
        'app/Modules/Audit',
        'app/Modules/Audit/Application',
        'app/Modules/Audit/Application/Contracts',
        'app/Modules/Audit/Experience',
        'app/Modules/Audit/Experience/Controllers',
        'app/Modules/Audit/Experience/Routes',
        'app/Modules/Audit/Infrastructure',
        'app/Modules/Audit/Infrastructure/Models',
        'app/Modules/Audit/Providers',
        'app/Modules/Customers',
        'app/Modules/Customers/Application',
        'app/Modules/Customers/Domain',
        'app/Modules/Customers/Domain/Exceptions',
        'app/Modules/Customers/Experience',
        'app/Modules/Customers/Experience/Controllers',
        'app/Modules/Customers/Experience/Routes',
        'app/Modules/Customers/Infrastructure',
        'app/Modules/Customers/Infrastructure/Models',
        'app/Modules/Customers/Providers',
        'app/Modules/Dashboard',
        'app/Modules/Dashboard/Application',
        'app/Modules/Dashboard/Experience',
        'app/Modules/Dashboard/Experience/Controllers',
        'app/Modules/Dashboard/Experience/Routes',
        'app/Modules/Dashboard/Providers',
        'app/Modules/Finance',
        'app/Modules/Finance/Application',
        'app/Modules/Finance/Experience',
        'app/Modules/Finance/Experience/Controllers',
        'app/Modules/Finance/Experience/Routes',
        'app/Modules/Finance/Infrastructure',
        'app/Modules/Finance/Infrastructure/Models',
        'app/Modules/Finance/Providers',
        'app/Modules/Identity',
        'app/Modules/Identity/Application',
        'app/Modules/Identity/Application/Contracts',
        'app/Modules/Identity/Experience',
        'app/Modules/Identity/Experience/Console',
        'app/Modules/Identity/Experience/Console/Commands',
        'app/Modules/Identity/Experience/Controllers',
        'app/Modules/Identity/Experience/Fortify',
        'app/Modules/Identity/Experience/Middleware',
        'app/Modules/Identity/Experience/Routes',
        'app/Modules/Identity/Infrastructure',
        'app/Modules/Identity/Infrastructure/Models',
        'app/Modules/Identity/Providers',
        'app/Modules/Logistics',
        'app/Modules/Logistics/Application',
        'app/Modules/Logistics/Experience',
        'app/Modules/Logistics/Experience/Controllers',
        'app/Modules/Logistics/Experience/Routes',
        'app/Modules/Logistics/Infrastructure',
        'app/Modules/Logistics/Infrastructure/Models',
        'app/Modules/Logistics/Providers',
        'app/Modules/Orders',
        'app/Modules/Orders/Application',
        'app/Modules/Orders/Experience',
        'app/Modules/Orders/Experience/Controllers',
        'app/Modules/Orders/Experience/Routes',
        'app/Modules/Orders/Infrastructure',
        'app/Modules/Orders/Infrastructure/Models',
        'app/Modules/Orders/Providers',
        'app/Modules/Runtime',
        'app/Modules/Runtime/Application',
        'app/Modules/Runtime/Application/Contracts',
        'app/Modules/Runtime/Experience',
        'app/Modules/Runtime/Experience/Console',
        'app/Modules/Runtime/Experience/Console/Commands',
        'app/Modules/Runtime/Experience/Controllers',
        'app/Modules/Runtime/Experience/Jobs',
        'app/Modules/Runtime/Experience/Routes',
        'app/Modules/Runtime/Infrastructure',
        'app/Modules/Runtime/Providers',
        'app/Modules/Shell',
        'app/Modules/Shell/Application',
        'app/Modules/Shell/Experience',
        'app/Modules/Shell/Experience/Controllers',
        'app/Modules/Shell/Experience/Routes',
        'app/Modules/Shell/Providers',
        'app/Modules/Sync',
        'app/Modules/Sync/Application',
        'app/Modules/Sync/Application/Contracts',
        'app/Modules/Sync/Experience',
        'app/Modules/Sync/Experience/Controllers',
        'app/Modules/Sync/Experience/Routes',
        'app/Modules/Sync/Infrastructure',
        'app/Modules/Sync/Infrastructure/Models',
        'app/Modules/Sync/Providers',
    ];

    private const ROUTE_NAMES = [
        'accounting.actual-2025', 'accounting.cost-calculation', 'accounting.execution-2026',
        'accounting.forecast-2027', 'accounting.kol-commissions', 'accounting.monthly',
        'accounting.optimization', 'accounting.order-costs', 'accounting.variance',
        'analysis.export', 'analysis.update', 'architecture.foundation',
        'customers.companies', 'customers.companies.archive', 'customers.companies.create', 'customers.companies.edit',
        'customers.companies.show', 'customers.companies.store', 'customers.companies.update',
        'customers.orders', 'customers.orders.create',
        'customers.orders.mine', 'dashboard', 'guides.manuals', 'guides.videos',
        'inventory.files', 'inventory.warehouses', 'kol.orders', 'kol.orders.create',
        'kol.orders.mine', 'kol.orders.show', 'kol.orders.team', 'login', 'login.store',
        'logistics.awaiting-tracking', 'logistics.exceptions', 'logistics.in-transit',
        'logistics.pending', 'logistics.payload', 'logistics.reopen', 'logistics.ship', 'logout', 'password.confirm.store', 'password.confirmation',
        'procurement.files', 'procurement.records', 'products.sku-aliases', 'products.skus',
        'root', 'settings.audit-logs', 'settings.roles-permissions', 'settings.statuses',
        'settings.users', 'settings.warehouses', 'shell.accounting', 'shell.customers',
        'shell.guides', 'shell.inventory', 'shell.kol', 'shell.logistics', 'shell.procurement',
        'shell.products', 'shell.settings', 'shell.sync', 'storage.local', 'storage.local.upload',
        'sync.complete', 'sync.payload', 'sync.reopen', 'sync.return', 'orders.export', 'orders.store', 'orders.store.customers', 'orders.update', 'orders.update.customers', 'customers.orders.show', 'system.audit-records.index', 'system.runtime.health',
    ];

    private const PERMISSION_KEYS = [
        'accounting.view', 'analysis.view', 'architecture.foundation.view', 'audit.view', 'customers.operate', 'customers.view',
        'dashboard.view', 'guides.view', 'inventory.view', 'kol.view', 'logistics.operate', 'logistics.view',
        'orders.create', 'orders.edit', 'orders.view', 'procurement.view', 'products.view', 'prototype.view', 'sync.operate', 'sync.view',
        'system.permissions.manage',
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
        'database/migrations/2026_08_05_000000_create_orders_tables.php' => 'c569493a9c671a44d8ff2e5d57624e4f4a8f3aba07b0b05baa255cfd0d855319',
        'database/migrations/2026_08_05_000001_create_shipments_table.php' => 'b77bd4fe75277ac2173689aa1fcfc8f81d91b13e7c9e53a1a5d49c51569c010d',
        'database/migrations/2026_08_05_000002_create_manual_syncs_table.php' => 'a71467bb42ab9c0a2edcbc9519619138c4bd8e5b0e15b5246e6f9a6a44fa14d5',
        'database/migrations/2026_08_05_000003_create_order_costs_table.php' => '107416001d2e658fad74870e656b2d23f45d21dbe2a892d512af1d043bd815cb',
        'database/migrations/2026_08_23_000000_create_companies_table.php' => '860faaca4a5c8f2654164798fc4d9717df2f291642f4c5754a1fda22ac652401',
    ];

    private const FRONTEND_FILES = [
        'resources/js/Components/AppShell/GlobalNavigation.test.ts' => '49d9b31f8a296a545117a6593de4eba26126c5a9865b5df9c39b6dd5688d5e49',
        'resources/js/Components/AppShell/GlobalNavigation.vue' => 'd770da59371440e8b4108aaa4116f50f07baa0b1980292d203a7936d702bf6dc',
        'resources/js/Components/AppShell/ModuleSidebar.vue' => '127f19089943410e88558a4f4fe07f7003c318c353b29582d526bdfcd64bc5a4',
        'resources/js/Components/AppShell/NavigationItem.vue' => 'a01afcb25099612ce549b48f3504f998c3ab71a112576e88792c5c1f99f4ead2',
        'resources/js/Components/AppShell/TopBar.vue' => '8ee9469043fa193fa2a3435331cfd4b656fc9cd27950e6138d5ea66e56ca6e3e',
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
        'resources/js/Layouts/AuthenticatedLayout.test.ts' => 'f43ad24ee595b6203987fd69746a9a3eae98f0e5619e70c19a91b5e3555d3fde',
        'resources/js/Layouts/AuthenticatedLayout.vue' => '50423cdd09161ce7cf36aade3f0375231571af27671dc7fc3055623442a80d2b',
        'resources/js/Pages/Architecture/Foundation.test.ts' => '68898785345d5cc641bcc8b3f165527a94c912e33c5f1941554a9a28aad97a1c',
        'resources/js/Pages/Architecture/Foundation.vue' => '0f3f96bd3ad9a5b68fa7fe5a78e20a1e0e92f4d2e6806ef30697fda64941aa70',
        'resources/js/Pages/Auth/Login.test.ts' => '937fcfd44e8348130d7abded4669263142d4cfc423f3ac4d87cee2a7889eec65',
        'resources/js/Pages/Auth/Login.vue' => '203bd9c4dc2ae13b8f5e185c4ebebcb5a4b4a088ae43eaf868b62cddf73d8901',
        'resources/js/Pages/Customers/Companies/Create.test.ts' => '2221b8d4e063e0615a97af14e6fbb738022fd44b55080a6fa6fa8c14d500e198',
        'resources/js/Pages/Customers/Companies/Create.vue' => '568e82d1d2587204b48319443d0d7958e2d053be901288ac02bdee2a58c68bb8',
        'resources/js/Pages/Customers/Companies/Edit.vue' => '7ffbcb6d19a43a59a0f27b37057f411c315644148cc57237e6f232ebc02b547f',
        'resources/js/Pages/Customers/Companies/Index.test.ts' => '3c76e366ed71a29f7be90aa67b493e05e080429641a16a2cb401380984327ba8',
        'resources/js/Pages/Customers/Companies/Index.vue' => '09bcaacdfa320dc821ecb9c22fe1facd6fa3061fdb5710dec82c6723709a0411',
        'resources/js/Pages/Customers/Companies/Show.vue' => 'be0f325de4331b4b4cfc7a3c006500326c9abc9bc4d86d2c80209dab92f0f68b',
        'resources/js/Pages/Dashboard/Index.test.ts' => '27c63e094fcdec28a0e5677508723e149c90a5130efb98d4448fa2b7f51b4ae4',
        'resources/js/Pages/Dashboard/Index.vue' => '0c28a768afcaa181b54ab01c4ec08457a04f7ed98e5ba3b52a041d98492bc2bb',
        'resources/js/Pages/Finance/OrderAnalysis.vue' => '96216f9ce4b67dd10bc4627c7e09703c1752c030110425509839d9aaab25d0aa',
        'resources/js/Pages/Logistics/Index.vue' => 'd5cb5024e68ab800043d2ac14d206394ad6f44924f8ba6c73156d52f7c507c91',
        'resources/js/Pages/Orders/Create.vue' => '0ea13dfe14a72147e800ee1ba3fefb6bcd091ba97c61ec306a011459e0fb0060',
        'resources/js/Pages/Orders/Index.vue' => '42e49684dbd996c4c8dc5893f6314672dc28fb5ecdd638ee31fe04132d7b846d',
        'resources/js/Pages/Orders/Show.vue' => '7ea993e5745667b160cd81ef9997a5df890c53aadfa20e567b028f6bee68aa6f',
        'resources/js/Pages/Shell/Placeholder.test.ts' => 'd0623492800b81b76650531eec2b979747d98daf34dcd4320ba00438529bb781',
        'resources/js/Pages/Shell/Placeholder.vue' => 'e18f588432b7d29a025f7618f977bdb7457536e8ae8028c2c343ce5c7b95472f',
        'resources/js/Pages/Sync/Index.vue' => '127b59b51cfef855410b60002fe82737e349dbd51f4b40e753fc4f7ba92e06c8',
        'resources/js/app.js' => '40994ac10f73e394b96d6e4742be37921fc38e477dccfdfe9e8ab90cd5e6d7d4',
        'resources/js/app.ts' => 'c667a849465fad821d6d08babe410c6d44bd7412629198522f1d408f373823b3',
        'resources/js/types/navigation.ts' => '31e51f9100ce60a20831007af9c48d412b3aeaee487c5ffa99a15006dcd6e1c3',
    ];

    public function test_frozen_module_and_support_boundaries(): void
    {
        self::assertSame($this->sorted(self::MODULE_DIRECTORIES), $this->subdirectoriesUnder($this->path('app/Modules')));
        self::assertSame($this->sorted(self::MODULE_FILES), $this->allFilesUnder($this->path('app/Modules')));
        $this->assertFixedFileSet(self::SUPPORT_FILES, 'SUPPORT-001', 'app/Support');
        self::assertDirectoryDoesNotExist($this->path('app/Shared'));
    }

    public function test_layer_scans_are_limited_to_module_layer_directories(): void
    {
        $domainFiles = $this->moduleLayerFiles('Domain');
        $applicationFiles = $this->moduleLayerFiles('Application');
        $infrastructureDependencyFiles = [...$domainFiles, ...$applicationFiles];
        self::assertSame($this->sorted([
            'app/Modules/Customers/Domain/CountryCodeNormalizer.php',
            'app/Modules/Customers/Domain/Exceptions/InvalidCompanyAttributeException.php',
            'app/Modules/Customers/Domain/WebsiteDomainNormalizer.php',
        ]), $domainFiles);
        self::assertSame($this->sorted([
            'app/Modules/Audit/Application/AuditActor.php',
            'app/Modules/Audit/Application/AuditService.php',
            'app/Modules/Audit/Application/AuditSource.php',
            'app/Modules/Audit/Application/AuditWriteException.php',
            'app/Modules/Audit/Application/Contracts/AuditRecorder.php',
            'app/Modules/Audit/Application/Contracts/AuditWriter.php',
            'app/Modules/Dashboard/Application/DashboardMetrics.php',
            'app/Modules/Identity/Application/AuthorizationKeys.php',
            'app/Modules/Identity/Application/Contracts/AuditRecorder.php',
            'app/Modules/Identity/Application/DataScope.php',
            'app/Modules/Runtime/Application/Contracts/RedisRuntimeProbe.php',
            'app/Modules/Runtime/Application/QueueJobConvention.php',
            'app/Modules/Runtime/Application/QueueName.php',
            'app/Modules/Runtime/Application/RedisClientCapability.php',
            'app/Modules/Runtime/Application/RuntimeHealthService.php',
            'app/Modules/Shell/Application/ErpShellNavigation.php',
            'app/Modules/Shell/Application/LegacyErpNavigationPaths.php',
            'app/Modules/Sync/Application/Contracts/SyncOutboxRepository.php',
            'app/Modules/Sync/Application/SyncOutboxService.php',
            'app/Modules/Sync/Application/SyncOutboxStatus.php',
            'app/Modules/Sync/Application/SyncOutboxWriteException.php',
        'app/Modules/Customers/Application/CompanyService.php',
        'app/Modules/Finance/Application/OrderAnalysisService.php',
        'app/Modules/Logistics/Application/ShipmentService.php',
        'app/Modules/Orders/Application/OrderService.php',
        'app/Modules/Orders/Application/OrderSourceType.php',
        'app/Modules/Orders/Application/OrderStatus.php',
        'app/Modules/Sync/Application/ManualSyncService.php',
        'app/Modules/Sync/Application/ManualSyncStatus.php',
        ]), $applicationFiles);
        self::assertSame($this->sorted([...$domainFiles, ...$applicationFiles]), $this->sorted($infrastructureDependencyFiles));
        self::assertNotContains('app/Modules/Future/Providers/Provider.php', $domainFiles);
        self::assertNotContains('app/Modules/Future/Providers/Provider.php', $applicationFiles);
        self::assertSame([], $this->tokenizerViolations('DOM-001', $domainFiles, $this->domForbidden()));
        self::assertSame([], $this->tokenizerViolations('APP-001', $applicationFiles, $this->appForbidden()));
        self::assertSame([], $this->tokenizerViolations('INF-001', $infrastructureDependencyFiles, ['Infrastructure']));
    }

    public function test_domain_application_and_infrastructure_rules_have_allowed_and_violating_fixtures(): void
    {
        $domainAllowed = "<?php\nnamespace App\\Modules\\Orders\\Domain;\nuse App\\Modules\\Orders\\Domain\\Order;\nfinal class Policy {}\n";
        self::assertSame([], $this->sourceViolations('DOM-001', 'app/Modules/Orders/Domain/Policy.php', $domainAllowed, $this->domForbidden()));
        $domainBad = "<?php\nnamespace App\\Modules\\Orders\\Domain;\nuse Illuminate\\Support\\Facades\\Route;\nuse App\\Modules\\Logistics\\Infrastructure\\Carrier;\n#[App\\Modules\\Orders\\Providers\\Marker]\nfinal class Policy {}\n";
        $domainViolations = $this->sourceViolations('DOM-001', 'app/Modules/Orders/Domain/Policy.php', $domainBad, $this->domForbidden());
        self::assertSame(['DOM-001', 'DOM-001', 'DOM-001'], array_column($domainViolations, 'RULE_ID'));
        self::assertSame([3, 4, 5], array_column($domainViolations, 'LINE'));
        self::assertSame(['Illuminate\\Support\\Facades\\Route', 'App\\Modules\\Logistics\\Infrastructure\\Carrier', 'App\\Modules\\Orders\\Providers\\Marker'], array_column($domainViolations, 'SYMBOL_OR_DEPENDENCY'));

        $appAllowed = "<?php\nnamespace App\\Modules\\Orders\\Application;\nuse App\\Modules\\Orders\\Application\\Contracts\\OrderReader;\nuse App\\Modules\\Orders\\Application\\Actions\\PlaceOrder;\nfinal class Handler {}\n";
        self::assertSame([], $this->sourceViolations('APP-001', 'app/Modules/Orders/Application/Handler.php', $appAllowed, $this->appForbidden()));
        $appBad = "<?php\nnamespace App\\Modules\\Orders\\Application;\nuse App\\Modules\\Orders\\Infrastructure\\SqlOrderReader;\nuse App\\Modules\\Logistics\\Application\\Internal\\CarrierLookup;\nfinal class Handler {}\n";
        $appViolations = $this->sourceViolations('APP-001', 'app/Modules/Orders/Application/Handler.php', $appBad, $this->appForbidden());
        self::assertSame([3, 4], array_column($appViolations, 'LINE'));
        self::assertSame(['App\\Modules\\Orders\\Infrastructure\\SqlOrderReader', 'App\\Modules\\Logistics\\Application\\Internal\\CarrierLookup'], array_column($appViolations, 'SYMBOL_OR_DEPENDENCY'));

        $domainInfraBad = "<?php\nnamespace App\\Modules\\Orders\\Domain;\nuse App\\Modules\\Orders\\Infrastructure\\EloquentOrder;\nfinal class Order {}\n";
        $domainInfraViolations = $this->sourceViolations('INF-001', 'app/Modules/Orders/Domain/Order.php', $domainInfraBad, ['Infrastructure']);
        self::assertSame(['INF-001'], array_column($domainInfraViolations, 'RULE_ID'));
        $appInfraBad = "<?php\nnamespace App\\Modules\\Orders\\Application;\nuse App\\Modules\\Orders\\Infrastructure\\SqlOrderRepository;\nfinal class Handler {}\n";
        $appInfraViolations = $this->sourceViolations('INF-001', 'app/Modules/Orders/Application/Handler.php', $appInfraBad, ['Infrastructure']);
        self::assertSame(['INF-001'], array_column($appInfraViolations, 'RULE_ID'));
        $infraAllowed = "<?php\nnamespace App\\Modules\\Orders\\Infrastructure;\nuse App\\Modules\\Orders\\Domain\\Order;\nuse App\\Modules\\Orders\\Application\\Contracts\\OrderRepository;\nfinal class EloquentOrderRepository implements OrderRepository {}\n";
        self::assertSame([], $this->sourceViolations('INF-001', 'app/Modules/Orders/Infrastructure/EloquentOrderRepository.php', $infraAllowed, ['Infrastructure']));
    }

    public function test_cross_module_rules_are_derived_from_the_current_module_name(): void
    {
        $ordersDomainBilling = "<?php\nnamespace App\\Modules\\Orders\\Domain;\nuse App\\Modules\\Billing\\Domain\\Invoice;\nfinal class Order {}\n";
        $domainViolations = $this->sourceViolations('DOM-001', 'app/Modules/Orders/Domain/Order.php', $ordersDomainBilling, $this->domForbidden());
        self::assertSame(['App\\Modules\\Billing\\Domain\\Invoice'], array_column($domainViolations, 'SYMBOL_OR_DEPENDENCY'));

        $ordersApplicationBillingInternal = "<?php\nnamespace App\\Modules\\Orders\\Application;\nuse App\\Modules\\Billing\\Application\\Internal\\BillingHandler;\nfinal class Handler {}\n";
        $internalViolations = $this->sourceViolations('APP-001', 'app/Modules/Orders/Application/Handler.php', $ordersApplicationBillingInternal, $this->appForbidden());
        self::assertSame(['App\\Modules\\Billing\\Application\\Internal\\BillingHandler'], array_column($internalViolations, 'SYMBOL_OR_DEPENDENCY'));

        $ordersApplicationBillingContract = "<?php\nnamespace App\\Modules\\Orders\\Application;\nuse App\\Modules\\Billing\\Application\\Contracts\\InvoiceReader;\nfinal class Handler {}\n";
        self::assertSame([], $this->sourceViolations('APP-001', 'app/Modules/Orders/Application/Handler.php', $ordersApplicationBillingContract, $this->appForbidden()));
    }

    public function test_module_namespace_must_match_its_file_path(): void
    {
        $matching = "<?php\nnamespace App\\Modules\\Orders\\Domain;\nfinal class Order {}\n";
        self::assertSame([], $this->sourceViolations('DOM-001', 'app/Modules/Orders/Domain/Order.php', $matching, $this->domForbidden()));

        $nested = "<?php\nnamespace App\\Modules\\Orders\\Domain\\ValueObjects;\nfinal class Money {}\n";
        self::assertSame([], $this->sourceViolations('DOM-001', 'app/Modules/Orders/Domain/ValueObjects/Money.php', $nested, $this->domForbidden()));

        $wrongModule = "<?php\nnamespace App\\Modules\\Billing\\Domain;\nfinal class Order {}\n";
        $wrongModuleViolations = $this->sourceViolations('DOM-001', 'app/Modules/Orders/Domain/Order.php', $wrongModule, $this->domForbidden());
        self::assertSame(['DOM-001'], array_column($wrongModuleViolations, 'RULE_ID'));
        self::assertSame('App\\Modules\\Billing\\Domain', $wrongModuleViolations[0]['SYMBOL_OR_DEPENDENCY']);

        $wrongLayer = "<?php\nnamespace App\\Modules\\Orders\\Infrastructure;\nfinal class Order {}\n";
        $wrongLayerViolations = $this->sourceViolations('DOM-001', 'app/Modules/Orders/Domain/Order.php', $wrongLayer, $this->domForbidden());
        self::assertSame(['DOM-001'], array_column($wrongLayerViolations, 'RULE_ID'));
        self::assertSame('App\\Modules\\Orders\\Infrastructure', $wrongLayerViolations[0]['SYMBOL_OR_DEPENDENCY']);
        self::assertSame(['RULE_ID', 'FILE', 'LINE', 'SYMBOL_OR_DEPENDENCY', 'REASON'], array_keys($wrongLayerViolations[0]));

        $missing = "<?php\nfinal class Order {}\n";
        $missingViolations = $this->sourceViolations('DOM-001', 'app/Modules/Orders/Domain/Order.php', $missing, $this->domForbidden());
        self::assertSame(['DOM-001'], array_column($missingViolations, 'RULE_ID'));
        self::assertSame(1, $missingViolations[0]['LINE']);
        self::assertSame('MISSING_NAMESPACE', $missingViolations[0]['SYMBOL_OR_DEPENDENCY']);
        self::assertSame('module file must declare a namespace matching its module layer path', $missingViolations[0]['REASON']);
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
        self::assertSame(array_keys(self::FRONTEND_FILES), $this->allFilesUnder($this->path('resources/js')), 'FE-001 frontend file set changed');
        self::assertSame('452406a1070ed29353684a39c70afc6d5d59cb9d39f7d0effa0752ba081994dd', hash_file('sha256', $this->path('menu/APPROVED_MENU.json')));
        self::assertSame($this->sorted(self::ROUTE_NAMES), $this->routeNames(), 'COMPAT-001 route names changed');
        $permissionKeys = array_values(array_filter(array_map(fn ($key): ?string => str_contains($key->value, '.') ? $key->value : null, AuthorizationKeys::cases())));
        sort($permissionKeys);
        self::assertSame(self::PERMISSION_KEYS, $permissionKeys, 'COMPAT-001 permission keys changed');
        $appProvider = (string) file_get_contents($this->path('app/Providers/AppServiceProvider.php'));
        $auditProvider = (string) file_get_contents($this->path('app/Modules/Audit/Providers/AuditServiceProvider.php'));
        self::assertStringContainsString('AuditWriter::class', $auditProvider);
        $identityFortify = (string) file_get_contents($this->path('app/Modules/Identity/Experience/Fortify/IdentityFortify.php'));
        foreach (['Gate::before', 'Gate::define', 'AuthorizationKeys::cases'] as $evidence) {
            self::assertStringContainsString($evidence, $identityFortify);
        }
        self::assertSame(ServiceProvider::class, get_parent_class(ModulesServiceProvider::class));
        self::assertSame([
            IdentityServiceProvider::class,
            OrdersServiceProvider::class,
            LogisticsServiceProvider::class,
            FinanceServiceProvider::class,
            RuntimeServiceProvider::class,
            AuditServiceProvider::class,
            SyncServiceProvider::class,
            ManualSyncServiceProvider::class,
            ShellServiceProvider::class,
            DashboardServiceProvider::class,
            CustomersServiceProvider::class,
        ], ModuleManifest::providers());
        self::assertSame([AppServiceProvider::class, ModulesServiceProvider::class], require $this->path('bootstrap/providers.php'));
    }

    /** @return list<string> */
    private function moduleLayerFiles(string $layer): array
    {
        $files = [];
        foreach (glob($this->path('app/Modules/*'), GLOB_ONLYDIR) ?: [] as $module) {
            $directory = $module.'/'.$layer;
            if (! is_dir($directory)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
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
        $records = $this->namespaceViolations($ruleId, $file, $source);
        $forbidden = [...$forbidden, ...$this->moduleBoundaryForbidden($ruleId, $file)];
        $tokens = token_get_all($source);
        $usePrefix = '';
        $inUse = false;
        $inNamespace = false;
        $braceDepth = 0;
        foreach ($tokens as $tokenIndex => $token) {
            if (! is_array($token)) {
                if ($inNamespace && $token === ';') {
                    $inNamespace = false;
                }
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
            if ($type === T_NAMESPACE) {
                $inNamespace = true;

                continue;
            }
            if ($inNamespace) {
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
                    $symbol = trim($usePrefix, '\\').'\\'.$symbol;
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
                while (isset($tokens[$nextIndex]) && is_array($tokens[$nextIndex]) && $tokens[$nextIndex][0] === T_WHITESPACE) {
                    $nextIndex++;
                }
                $nextToken = $tokens[$nextIndex] ?? null;
                if ($nextToken === '{' || (is_array($nextToken) && $nextToken[0] === T_NS_SEPARATOR)) {
                    continue;
                }
            }
            foreach ($forbidden as $dependency) {
                $matches = $dependency === 'App\\Modules\\'
                    ? $this->matchesModuleBoundary($ruleId, $file, $symbol)
                    : $this->matchesDependency($symbol, $dependency);
                if ($matches) {
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

    private function matchesModuleBoundary(string $ruleId, string $file, string $symbol): bool
    {
        if (! preg_match('#^app/Modules/([^/]+)/(Domain|Application)/#', $file, $fileMatches)) {
            return false;
        }
        if (! preg_match('#^App\\\\Modules\\\\([^\\\\]+)\\\\(.+)$#', $symbol, $symbolMatches)) {
            return false;
        }
        if ($symbolMatches[1] === $fileMatches[1]) {
            return false;
        }

        return $ruleId === 'DOM-001'
            || ($ruleId === 'APP-001' && str_starts_with($symbolMatches[2], 'Application\\Internal\\'));
    }

    /** @return list<string> */
    private function moduleBoundaryForbidden(string $ruleId, string $file): array
    {
        if (! preg_match('#^app/Modules/([^/]+)/(Domain|Application)/#', $file, $matches)) {
            return [];
        }
        $module = $matches[1];
        if ($ruleId === 'DOM-001') {
            return ['App\\Modules\\'];
        }
        if ($ruleId === 'APP-001') {
            return ['App\\Modules\\'];
        }

        return [];
    }

    /** @return list<array{RULE_ID:string,FILE:string,LINE:int,SYMBOL_OR_DEPENDENCY:string,REASON:string}> */
    private function namespaceViolations(string $ruleId, string $file, string $source): array
    {
        if (! preg_match('#^app/Modules/([^/]+)/(Domain|Application|Infrastructure|Providers)/#', $file, $matches)) {
            return [];
        }
        $expected = 'App\\Modules\\'.$matches[1].'\\'.$matches[2];
        $tokens = token_get_all($source);
        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_NAMESPACE) {
                continue;
            }
            $namespace = '';
            $line = $token[2];
            for ($cursor = $index + 1; isset($tokens[$cursor]); $cursor++) {
                $part = $tokens[$cursor];
                if ($part === ';' || $part === '{') {
                    break;
                }
                if (is_array($part) && in_array($part[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $namespace .= trim($part[1], '\\');
                } elseif ($part === '\\') {
                    $namespace .= '\\';
                }
            }
            if ($namespace !== $expected && ! str_starts_with($namespace, $expected.'\\')) {
                return [[
                    'RULE_ID' => $ruleId,
                    'FILE' => $file,
                    'LINE' => $line,
                    'SYMBOL_OR_DEPENDENCY' => $namespace,
                    'REASON' => 'declared namespace does not match module file path',
                ]];
            }

            return [];
        }

        return [[
            'RULE_ID' => $ruleId,
            'FILE' => $file,
            'LINE' => 1,
            'SYMBOL_OR_DEPENDENCY' => 'MISSING_NAMESPACE',
            'REASON' => 'module file must declare a namespace matching its module layer path',
        ]];
    }

    /** @return list<string> */
    private function domForbidden(): array
    {
        return ['Illuminate\\', 'Facade', 'Controller', 'Request', 'Route', 'Inertia', 'Eloquent', 'Infrastructure', 'Experience', 'Providers'];
    }

    /** @return list<string> */
    private function appForbidden(): array
    {
        return ['Infrastructure'];
    }

    /** @return list<string> */
    private function providerForbidden(): array
    {
        return ['filesystem', 'glob', 'scandir', 'Finder', 'convention', 'Route::', 'Migration', 'View', 'Event', 'Queue', 'Schedule', 'Redis', '$name'];
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
        foreach ($expected as $path) {
            self::assertFileExists($this->path($path));
        }
        self::assertSame($expected, $this->filesUnder($this->path($directory)), $ruleId.' baseline file set changed');
    }

    /** @return list<string> */
    private function filesUnder(string $directory): array
    {
        $files = [];
        if (is_file($directory)) {
            return [str_replace($this->path().'/', '', $directory)];
        }
        if (! is_dir($directory)) {
            return [];
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = str_replace($this->path().'/', '', $file->getPathname());
            }
        }
        sort($files);

        return $files;
    }

    /** @return list<string> */
    private function allFilesUnder(string $directory): array
    {
        if (is_file($directory)) {
            return [str_replace($this->path().'/', '', $directory)];
        }
        if (! is_dir($directory)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
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
        $directories = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                $directories[] = str_replace($this->path().'/', '', $file->getPathname());
            }
        }
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

    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /** @return list<string> */
    private function routeNames(): array
    {
        $application = require $this->path('bootstrap/app.php');
        $application->make(Kernel::class)->bootstrap();
        $names = array_values(array_filter(array_map(
            fn (Route $route): ?string => $route->getName(),
            $application->make('router')->getRoutes()->getRoutes(),
        ), fn (?string $name): bool => $name !== null));
        sort($names);

        return $names;
    }
}
