<?php

namespace Tests\Architecture;

use App\Modules\Audit\Providers\AuditServiceProvider;
use App\Modules\Customers\Providers\CustomersServiceProvider;
use App\Modules\Dashboard\Providers\DashboardServiceProvider;
use App\Modules\Finance\Providers\FinanceServiceProvider;
use App\Modules\Guides\Providers\GuidesServiceProvider;
use App\Modules\Identity\Providers\IdentityServiceProvider;
use App\Modules\Inventory\Providers\InventoryServiceProvider;
use App\Modules\Kol\Providers\KolServiceProvider;
use App\Modules\Logistics\Providers\LogisticsServiceProvider;
use App\Modules\ModuleManifest;
use App\Modules\ModulesServiceProvider;
use App\Modules\Orders\Providers\OrdersServiceProvider;
use App\Modules\Products\Providers\ProductsServiceProvider;
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
        'app/Modules/Guides/Application/KnowledgeBaseService.php',
        'app/Modules/Guides/Application/MarkdownContentRenderer.php',
        'app/Modules/Guides/Experience/Controllers/GuideController.php',
        'app/Modules/Guides/Experience/Controllers/ReleaseNoteController.php',
        'app/Modules/Guides/Experience/Routes/web.php',
        'app/Modules/Guides/Infrastructure/Models/GuideArticle.php',
        'app/Modules/Guides/Infrastructure/Models/ReleaseNote.php',
        'app/Modules/Guides/Providers/GuidesServiceProvider.php',
        'app/Modules/Identity/Application/AuthorizationKeys.php',
        'app/Modules/Identity/Application/Contracts/AuditRecorder.php',
        'app/Modules/Identity/Application/DataScope.php',
        'app/Modules/Identity/Application/Exceptions/IdentityGuardException.php',
        'app/Modules/Identity/Application/PermissionCatalog.php',
        'app/Modules/Identity/Application/RolePermissionsService.php',
        'app/Modules/Identity/Application/RolePreset.php',
        'app/Modules/Identity/Application/UserService.php',
        'app/Modules/Identity/Experience/Console/Commands/CreateErpUser.php',
        'app/Modules/Identity/Experience/Controllers/AccountController.php',
        'app/Modules/Identity/Experience/Controllers/AuthController.php',
        'app/Modules/Identity/Experience/Controllers/RolePermissionsController.php',
        'app/Modules/Identity/Experience/Controllers/UserController.php',
        'app/Modules/Identity/Experience/Fortify/AttemptPersistentAuthentication.php',
        'app/Modules/Identity/Experience/Fortify/IdentityFortify.php',
        'app/Modules/Identity/Experience/Middleware/ApplyLocalAdminBypass.php',
        'app/Modules/Identity/Experience/Middleware/EnsureActiveAuthenticatedUser.php',
        'app/Modules/Identity/Experience/Middleware/RequireAnyPermission.php',
        'app/Modules/Identity/Experience/Routes/user-management.php',
        'app/Modules/Identity/Experience/Routes/web.php',
        'app/Modules/Identity/Infrastructure/Models/Permission.php',
        'app/Modules/Identity/Infrastructure/Models/Role.php',
        'app/Modules/Identity/Infrastructure/Models/User.php',
        'app/Modules/Identity/Providers/IdentityServiceProvider.php',
        'app/Modules/Inventory/Application/Contracts/InventoryAvailabilityReader.php',
        'app/Modules/Inventory/Application/Contracts/InventoryBatchSyncRepository.php',
        'app/Modules/Inventory/Application/Contracts/InventoryRepository.php',
        'app/Modules/Inventory/Application/InventoryBatchSyncService.php',
        'app/Modules/Inventory/Application/InventoryService.php',
        'app/Modules/Inventory/Application/Sync/ApiAdapterPlaceholder.php',
        'app/Modules/Inventory/Application/Sync/CsvAdapter.php',
        'app/Modules/Inventory/Application/Sync/NormalizedRow.php',
        'app/Modules/Inventory/Application/Sync/PasteAdapter.php',
        'app/Modules/Inventory/Application/Sync/RowNormalizer.php',
        'app/Modules/Inventory/Application/Sync/XlsxAdapter.php',
        'app/Modules/Inventory/Experience/Controllers/InventoryController.php',
        'app/Modules/Inventory/Experience/Controllers/InventorySyncController.php',
        'app/Modules/Inventory/Experience/Controllers/WarehouseController.php',
        'app/Modules/Inventory/Experience/Routes/web.php',
        'app/Modules/Inventory/Infrastructure/EloquentInventoryAvailabilityReader.php',
        'app/Modules/Inventory/Infrastructure/EloquentInventoryBatchSyncRepository.php',
        'app/Modules/Inventory/Infrastructure/EloquentInventoryRepository.php',
        'app/Modules/Inventory/Infrastructure/Models/InventoryAdjustment.php',
        'app/Modules/Inventory/Infrastructure/Models/InventorySyncBatch.php',
        'app/Modules/Inventory/Infrastructure/Models/InventorySyncRow.php',
        'app/Modules/Inventory/Infrastructure/Models/Warehouse.php',
        'app/Modules/Inventory/Infrastructure/Models/WarehouseInventory.php',
        'app/Modules/Inventory/Infrastructure/Models/WarehouseInventoryLot.php',
        'app/Modules/Inventory/Infrastructure/Models/WarehouseServiceRegion.php',
        'app/Modules/Inventory/Providers/InventoryServiceProvider.php',
        'app/Modules/Kol/Application/KolScope.php',
        'app/Modules/Kol/Application/KolService.php',
        'app/Modules/Kol/Application/SavedViewService.php',
        'app/Modules/Kol/Experience/Controllers/KolCollaborationController.php',
        'app/Modules/Kol/Experience/Controllers/KolCommunicationController.php',
        'app/Modules/Kol/Experience/Controllers/KolController.php',
        'app/Modules/Kol/Experience/Controllers/KolOpportunityController.php',
        'app/Modules/Kol/Experience/Controllers/KolOverviewController.php',
        'app/Modules/Kol/Experience/Controllers/SavedViewController.php',
        'app/Modules/Kol/Experience/Routes/web.php',
        'app/Modules/Kol/Infrastructure/Models/Kol.php',
        'app/Modules/Kol/Infrastructure/Models/KolCollaboration.php',
        'app/Modules/Kol/Infrastructure/Models/KolCollaborationProduct.php',
        'app/Modules/Kol/Infrastructure/Models/KolCommunication.php',
        'app/Modules/Kol/Infrastructure/Models/KolOpportunity.php',
        'app/Modules/Kol/Infrastructure/Models/KolSalesPerformance.php',
        'app/Modules/Kol/Infrastructure/Models/KolSocialAccount.php',
        'app/Modules/Kol/Infrastructure/Models/KolSocialMetric.php',
        'app/Modules/Kol/Infrastructure/Models/SavedView.php',
        'app/Modules/Kol/Providers/KolServiceProvider.php',
        'app/Modules/Logistics/Application/ShipmentService.php',
        'app/Modules/Logistics/Experience/Controllers/CarrierController.php',
        'app/Modules/Logistics/Experience/Controllers/LogisticsController.php',
        'app/Modules/Logistics/Experience/Routes/web.php',
        'app/Modules/Logistics/Infrastructure/Models/Shipment.php',
        'app/Modules/Logistics/Infrastructure/Models/ShipmentCarrierBilling.php',
        'app/Modules/Logistics/Providers/LogisticsServiceProvider.php',
        'app/Modules/ModuleManifest.php',
        'app/Modules/ModulesServiceProvider.php',
        'app/Modules/Orders/Application/Contracts/WooCommerceOrderGateway.php',
        'app/Modules/Orders/Application/Inbound/NormalizedInboundOrder.php',
        'app/Modules/Orders/Application/Inbound/WooCommerceInboundService.php',
        'app/Modules/Orders/Application/Inbound/WooCommerceOrderNormalizer.php',
        'app/Modules/Orders/Application/OrderKind.php',
        'app/Modules/Orders/Application/OrderKindBackfill.php',
        'app/Modules/Orders/Application/OrderService.php',
        'app/Modules/Orders/Application/OrderSourceType.php',
        'app/Modules/Orders/Application/OrderStatus.php',
        'app/Modules/Orders/Experience/Controllers/OrderController.php',
        'app/Modules/Orders/Experience/Controllers/OrderSavedViewController.php',
        'app/Modules/Orders/Experience/Controllers/WooCommerceInboundController.php',
        'app/Modules/Orders/Experience/Routes/web.php',
        'app/Modules/Orders/Infrastructure/Models/Order.php',
        'app/Modules/Orders/Infrastructure/Models/OrderAddress.php',
        'app/Modules/Orders/Infrastructure/Models/OrderExternalReference.php',
        'app/Modules/Orders/Infrastructure/Models/OrderItem.php',
        'app/Modules/Orders/Infrastructure/Models/OrderOperationalMarker.php',
        'app/Modules/Orders/Infrastructure/WooCommerce/HttpWooCommerceOrderGateway.php',
        'app/Modules/Orders/Providers/OrdersServiceProvider.php',
        'app/Modules/Products/Application/Contracts/ProductRepository.php',
        'app/Modules/Products/Application/ProductImportResult.php',
        'app/Modules/Products/Application/ProductService.php',
        'app/Modules/Products/Experience/Console/ImportWooCsvCommand.php',
        'app/Modules/Products/Experience/Controllers/ProductController.php',
        'app/Modules/Products/Experience/Routes/web.php',
        'app/Modules/Products/Infrastructure/EloquentProductRepository.php',
        'app/Modules/Products/Infrastructure/Models/Product.php',
        'app/Modules/Products/Providers/ProductsServiceProvider.php',
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
        'app/Modules/Shell/Application/ErpShellNavigation.php',
        'app/Modules/Shell/Application/LegacyErpNavigationPaths.php',
        'app/Modules/Shell/Experience/Controllers/ErpShellController.php',
        'app/Modules/Shell/Experience/Controllers/ShellPlaceholderController.php',
        'app/Modules/Shell/Experience/Routes/web.php',
        'app/Modules/Shell/Providers/ShellServiceProvider.php',
        'app/Modules/Sync/Application/Contracts/SyncOrchestrationRepository.php',
        'app/Modules/Sync/Application/Contracts/SyncOutboxRepository.php',
        'app/Modules/Sync/Application/Contracts/SyncRunRepository.php',
        'app/Modules/Sync/Application/Contracts/WooCommerceTrackingGateway.php',
        'app/Modules/Sync/Application/ManualSyncService.php',
        'app/Modules/Sync/Application/ManualSyncStatus.php',
        'app/Modules/Sync/Application/SyncOrchestrationService.php',
        'app/Modules/Sync/Application/SyncOutboxService.php',
        'app/Modules/Sync/Application/SyncOutboxStatus.php',
        'app/Modules/Sync/Application/SyncOutboxWriteException.php',
        'app/Modules/Sync/Application/SyncRunItemStatus.php',
        'app/Modules/Sync/Application/SyncRunService.php',
        'app/Modules/Sync/Application/SyncRunStatus.php',
        'app/Modules/Sync/Application/WooCommerceTrackingConflictException.php',
        'app/Modules/Sync/Application/WooCommerceTrackingOutboundService.php',
        'app/Modules/Sync/Experience/Controllers/ManualSyncController.php',
        'app/Modules/Sync/Experience/Controllers/SyncOrchestrationController.php',
        'app/Modules/Sync/Experience/Controllers/SyncWorkspaceController.php',
        'app/Modules/Sync/Experience/Controllers/WooCommerceTrackingOutboundController.php',
        'app/Modules/Sync/Experience/Routes/web.php',
        'app/Modules/Sync/Infrastructure/EloquentSyncOrchestrationRepository.php',
        'app/Modules/Sync/Infrastructure/EloquentSyncOutboxRepository.php',
        'app/Modules/Sync/Infrastructure/EloquentSyncRunRepository.php',
        'app/Modules/Sync/Infrastructure/Models/ManualSync.php',
        'app/Modules/Sync/Infrastructure/Models/SyncOutbox.php',
        'app/Modules/Sync/Infrastructure/Models/SyncRun.php',
        'app/Modules/Sync/Infrastructure/Models/SyncRunItem.php',
        'app/Modules/Sync/Infrastructure/WooCommerce/HttpWooCommerceTrackingGateway.php',
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
        'app/Modules/Guides',
        'app/Modules/Guides/Application',
        'app/Modules/Guides/Experience',
        'app/Modules/Guides/Experience/Controllers',
        'app/Modules/Guides/Experience/Routes',
        'app/Modules/Guides/Infrastructure',
        'app/Modules/Guides/Infrastructure/Models',
        'app/Modules/Guides/Providers',
        'app/Modules/Identity',
        'app/Modules/Identity/Application',
        'app/Modules/Identity/Application/Contracts',
        'app/Modules/Identity/Application/Exceptions',
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
        'app/Modules/Inventory',
        'app/Modules/Inventory/Application',
        'app/Modules/Inventory/Application/Contracts',
        'app/Modules/Inventory/Application/Sync',
        'app/Modules/Inventory/Experience',
        'app/Modules/Inventory/Experience/Controllers',
        'app/Modules/Inventory/Experience/Routes',
        'app/Modules/Inventory/Infrastructure',
        'app/Modules/Inventory/Infrastructure/Models',
        'app/Modules/Inventory/Providers',
        'app/Modules/Kol',
        'app/Modules/Kol/Application',
        'app/Modules/Kol/Experience',
        'app/Modules/Kol/Experience/Controllers',
        'app/Modules/Kol/Experience/Routes',
        'app/Modules/Kol/Infrastructure',
        'app/Modules/Kol/Infrastructure/Models',
        'app/Modules/Kol/Providers',
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
        'app/Modules/Orders/Application/Contracts',
        'app/Modules/Orders/Application/Inbound',
        'app/Modules/Orders/Experience',
        'app/Modules/Orders/Experience/Controllers',
        'app/Modules/Orders/Experience/Routes',
        'app/Modules/Orders/Infrastructure',
        'app/Modules/Orders/Infrastructure/Models',
        'app/Modules/Orders/Infrastructure/WooCommerce',
        'app/Modules/Orders/Providers',
        'app/Modules/Products',
        'app/Modules/Products/Application',
        'app/Modules/Products/Application/Contracts',
        'app/Modules/Products/Experience',
        'app/Modules/Products/Experience/Console',
        'app/Modules/Products/Experience/Controllers',
        'app/Modules/Products/Experience/Routes',
        'app/Modules/Products/Infrastructure',
        'app/Modules/Products/Infrastructure/Models',
        'app/Modules/Products/Providers',
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
        'app/Modules/Sync/Infrastructure/WooCommerce',
        'app/Modules/Sync/Providers',
    ];

    private const ROUTE_NAMES = [
        'account.password',
        'account.password.update',
        'account.profile',
        'account.profile.update',
        'accounting.actual-2025',
        'accounting.cost-calculation',
        'accounting.execution-2026',
        'accounting.forecast-2027',
        'accounting.kol-commissions',
        'accounting.monthly',
        'accounting.optimization',
        'accounting.order-costs',
        'accounting.variance',
        'analysis.export',
        'analysis.update',
        'architecture.foundation',
        'customers.companies',
        'customers.companies.archive',
        'customers.companies.create',
        'customers.companies.edit',
        'customers.companies.restore',
        'customers.companies.show',
        'customers.companies.store',
        'customers.companies.update',
        'customers.orders',
        'customers.orders.create',
        'customers.orders.mine',
        'customers.orders.show',
        'dashboard',
        'guides.create',
        'guides.edit',
        'guides.manuals',
        'guides.publish',
        'guides.releases.create',
        'guides.releases.edit',
        'guides.releases.index',
        'guides.releases.publish',
        'guides.releases.show',
        'guides.releases.store',
        'guides.releases.unpublish',
        'guides.releases.update',
        'guides.show',
        'guides.store',
        'guides.unpublish',
        'guides.update',
        'guides.videos',
        'inventory.add',
        'inventory.list',
        'inventory.product-search',
        'inventory.products',
        'inventory.stock.store',
        'inventory.stock.update',
        'inventory.store',
        'inventory.sync',
        'inventory.sync.confirm',
        'inventory.sync.history',
        'inventory.sync.preview',
        'inventory.sync.show',
        'inventory.warehouse.billing',
        'inventory.warehouse.regions',
        'inventory.warehouse.show',
        'inventory.warehouse.update',
        'inventory.warehouses',
        'kol.affiliate',
        'kol.collaborations',
        'kol.commissions',
        'kol.communications',
        'kol.content',
        'kol.library',
        'kol.library.archive',
        'kol.library.avatar',
        'kol.library.collaborations.fulfillment-order',
        'kol.library.collaborations.store',
        'kol.library.collaborations.update',
        'kol.library.communications.store',
        'kol.library.communications.update',
        'kol.library.create',
        'kol.library.edit',
        'kol.library.opportunities.convert-collaboration',
        'kol.library.opportunities.store',
        'kol.library.opportunities.update',
        'kol.library.reactivate',
        'kol.library.show',
        'kol.library.store',
        'kol.library.update',
        'kol.opportunities',
        'kol.orders',
        'kol.orders.create',
        'kol.orders.mine',
        'kol.orders.show',
        'kol.orders.team',
        'kol.overview',
        'kol.samples',
        'kol.views.destroy',
        'kol.views.store',
        'kol.views.update',
        'login',
        'login.store',
        'logistics.awaiting-tracking',
        'logistics.carriers',
        'logistics.carriers.show',
        'logistics.draft',
        'logistics.exceptions',
        'logistics.in-transit',
        'logistics.payload',
        'logistics.pending',
        'logistics.reopen',
        'logistics.ship',
        'logout',
        'orders.export',
        'orders.export.all',
        'orders.export.procurement',
        'orders.index',
        'orders.operational-markers.update',
        'orders.procurement',
        'orders.store',
        'orders.store.customers',
        'orders.update',
        'orders.update.customers',
        'orders.views.destroy',
        'orders.views.store',
        'orders.views.update',
        'password.confirm.store',
        'password.confirmation',
        'procurement.files',
        'procurement.records',
        'products.create',
        'products.dashboard',
        'products.destroy',
        'products.edit',
        'products.import',
        'products.import.store',
        'products.list',
        'products.sku-aliases',
        'products.sku-search',
        'products.skus',
        'products.store',
        'products.update',
        'root',
        'settings.audit-logs',
        'settings.changelog',
        'settings.roles-permissions',
        'settings.roles-permissions.update',
        'settings.statuses',
        'settings.warehouses',
        'shell.accounting',
        'shell.customers',
        'shell.guides',
        'shell.inventory',
        'shell.kol',
        'shell.logistics',
        'shell.procurement',
        'shell.settings',
        'shell.sync',
        'storage.local',
        'storage.local.upload',
        'sync.complete',
        'sync.exports',
        'sync.kol',
        'sync.orchestration.reconcile',
        'sync.orchestration.recover-stale',
        'sync.orchestration.retry',
        'sync.orchestration.tracking-all-pending',
        'sync.orchestration.tracking-selected',
        'sync.orders',
        'sync.payload',
        'sync.reopen',
        'sync.return',
        'sync.runs.show',
        'sync.tracking-outbound',
        'sync.warehouses',
        'system.audit-records.index',
        'system.runtime.health',
        'user.create',
        'user.destroy',
        'user.disable',
        'user.edit',
        'user.enable',
        'user.index',
        'user.password',
        'user.permissions',
        'user.role',
        'user.store',
        'user.update',
        'woocommerce.inbound.historical',
        'woocommerce.inbound.incremental',
        'woocommerce.inbound.index',
        'woocommerce.inbound.probe',
        'woocommerce.inbound.single',
    ];

    private const PERMISSION_KEYS = [
        'accounting.view', 'analysis.view', 'architecture.foundation.view', 'audit.view', 'customers.operate', 'customers.view',
        'dashboard.view', 'guides.manage', 'guides.view', 'inventory.operate', 'inventory.view', 'inventory.warehouses.manage',
        'kol.operate', 'kol.view', 'kol.views.manage', 'logistics.operate', 'logistics.view',
        'orders.create', 'orders.edit', 'orders.view', 'procurement.view', 'products.operate', 'products.view', 'prototype.view', 'sync.operate', 'sync.view',
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
        'database/migrations/2026_08_24_000000_add_order_kind_to_orders_table.php' => '7775e65ca4ec9e4d2de5fe6b603bf8118b35d2f999f95f8e85471ef280dd89c8',
        'database/migrations/2026_08_24_000000_add_permission_mode_and_permission_user_pivot.php' => '8f84c0b97530d31395cfbc7968476b1734498019bf47830e7974ed7d59af3be6',
        'database/migrations/2026_08_24_000001_make_order_address_snapshot_fields_nullable.php' => '0c3df13a4def87cb761dbac6c2a15b58fdf3c1352431456f39992b85f9ceaaf9',
        'database/migrations/2026_08_24_100000_create_products_table.php' => 'a7ddaa6125c296d3b67fd85eb8c3728764630207a121d57046702defb3bda1c0',
        'database/migrations/2026_08_25_000100_create_inventory_foundation_tables.php' => '5c04ba76e1e90e51970b492aca4f386ed02270eb4f201493d1d90ccd74794e07',
        'database/migrations/2026_08_25_000101_seed_initial_warehouses.php' => '47ac9451febaa5b34ddc0ab6e76e2a208264227551caf4818dd0624afc0f5fa9',
        'database/migrations/2026_08_25_000200_create_inventory_batch_sync_tables.php' => 'a2e1ecba6592740d3708503e3260523ec33a2601618a591c1abf75f5ed8eb5c4',
        'database/migrations/2026_08_27_000000_repair_inventory_sync_preview_metrics.php' => 'd791cc4fc3aebecafcfff2c0fc8f5b02b51f142b3dc9986ee7f8fdcd17b7aded',
        'database/migrations/2026_08_27_020000_add_manual_fulfillment_fields_to_shipments.php' => '44d755cd840a87bbc96c48311f53751c6fc375f85d1fff5293090b5b8c2be0ad',
        'database/migrations/2026_08_27_171200_add_warehouse_ownership_type.php' => '758912e9318255ee88471503f3a1785f1430479e371e17f1add06e1d5cd5edad',
        'database/migrations/2026_08_27_181000_add_woocommerce_inbound_order_contract.php' => '24ade80cd3e84b05ac8c170b80d2120a479d59d6faab522bebb2fa0f4bc1b252',
        'database/migrations/2026_08_27_210000_create_kols_tables.php' => 'cca66cb015a6493a38f78ab8af0bfad144278d2565e86a27e63f4b124d28ed6c',
        'database/migrations/2026_08_27_210001_create_saved_views_table.php' => '848c4971ba83242e78578a373b78365782eb16c5a22a8af4e4755bd50c449602',
        'database/migrations/2026_08_27_220000_add_contact_shipping_payout_to_kols.php' => 'b1dde67cb168f0bcbef7d6f4c6820db054d8599ef32c1e0a3aa0612980115b85',
        'database/migrations/2026_08_27_230000_add_business_profile_and_contact_upgrade_to_kols.php' => '6ad45d9779d5233bd8c7f9f35f1bb13b74442aecef54410c5c33950b4e85a667',
        'database/migrations/2026_08_28_000000_create_sync_orchestration_tables.php' => '986373a6aa616b074c1edb119d6a4b2b30ba686170efae4374772d67749aa7c9',
        'database/migrations/2026_08_28_130000_create_kol_opportunities_table.php' => '232d1cb93ea17d3493651125d7d7ccf112a0d0bac4e7ca01e9aa3a8551efaa35',
        'database/migrations/2026_08_28_140000_create_kol_communications_table.php' => '8d616ba4c7114ff8a8455858cf6ccd9843ee0535e8022349f68cdcde70bcc5fa',
        'database/migrations/2026_08_28_150000_add_self_profile_fields_to_users.php' => 'abfbbda534229b2c5c7efcf5814ae76b212876a8157fdf8009e72e076d89b7c3',
        'database/migrations/2026_08_28_150000_refine_kol_business_profile.php' => 'b638db314c4752f609928bf3987425c68c5a096cd39ef9f424835171c32c2dbf',
        'database/migrations/2026_08_28_160000_add_avatar_to_kols_table.php' => 'aa1959bc10b7968bab270d0f4f61d9b2acba81a3d2cbcab2a2b850d6b04f428c',
        'database/migrations/2026_08_28_170000_add_direction_to_kol_communications_table.php' => 'e4027781e1f13e023a3ef30828996a98453ee367f9e60b425f6576efec0c8c1f',
        'database/migrations/2026_08_28_180000_create_kol_collaboration_tables.php' => 'b7ed969a02b22789e867fae0c6571f6ec730837235d7d8c37382d1af95c37860',
        'database/migrations/2026_08_28_180000_create_shipment_carrier_billing_table.php' => '4f1831fe201ee1f0b82f5dbdee6fffa72ccf01c752561e72b6563e2ce4aa40a0',
        'database/migrations/2026_08_28_181000_add_post_url_to_kol_collaborations.php' => 'd23e755f52b573a643e9e3817203a4c2b2a56dab2c31c42922b614d7a75d3127',
        'database/migrations/2026_08_28_182000_backfill_kol_collaboration_post_urls.php' => '75fd066462b688a7139784513cff15da24e4516c59a2bfdc8e460095a3863f98',
        'database/migrations/2026_08_28_190000_add_domestic_flag_to_warehouses.php' => 'f5afb1fbd1ae9191a9ae48063c878db1b3e4f050f7aa1a9655a582dac3e7f059',
        'database/migrations/2026_08_28_190000_add_order_id_to_kol_collaborations.php' => '8cd8bb15ed7aa905b9dbeb01ec4493b86875f1d9ef577d56e9b3695464b4b6ab',
        'database/migrations/2026_08_28_190100_create_order_operational_markers_table.php' => '2ef5a0f6ce5b3149fc0ef81768fbd2e7c0d910481939bb7f243d43b4dd9d4289',
        'database/migrations/2026_08_28_200000_create_guide_articles_table.php' => 'ff333c405ccec886ee764fcf6496abcd53dcd0428d7addd65129d0554fc6445e',
        'database/migrations/2026_08_28_200100_create_release_notes_table.php' => '1ad9359ac7185106b41677d4fd69eba3d4b5f84a222113bf24568e5aeb1161eb',
    ];

    private const FRONTEND_FILES = [
        'resources/js/Components/AppShell/GlobalFeedbackIsland.test.ts' => '36d4310edf520357dd153cc19de772d774e3473606881298d734ed28e1725ce1',
        'resources/js/Components/AppShell/GlobalFeedbackIsland.vue' => '17e7bcf0d540843934215cb537b82d243e3492b0760518f523500c15b1916cd9',
        'resources/js/Components/AppShell/GlobalNavigation.test.ts' => '49d9b31f8a296a545117a6593de4eba26126c5a9865b5df9c39b6dd5688d5e49',
        'resources/js/Components/AppShell/GlobalNavigation.vue' => 'd770da59371440e8b4108aaa4116f50f07baa0b1980292d203a7936d702bf6dc',
        'resources/js/Components/AppShell/ModuleSidebar.vue' => '127f19089943410e88558a4f4fe07f7003c318c353b29582d526bdfcd64bc5a4',
        'resources/js/Components/AppShell/NavigationItem.vue' => 'a01afcb25099612ce549b48f3504f998c3ab71a112576e88792c5c1f99f4ead2',
        'resources/js/Components/AppShell/TopBar.test.ts' => '2b49fac1367891c39b9202cf4d3c8d9b8eba7ab4a767e0be5b7c43f8ee6f9d09',
        'resources/js/Components/AppShell/TopBar.vue' => '1a4b9a6b31fee11bacb42abe74215e34cb0b3b7e798647a2765ef449db8d8a7c',
        'resources/js/Components/AppShell/UserAccountMenu.test.ts' => '4f5d8af12c7be66fa9a878f47f614e2d6e734e338aa71ed6415ed9caa93c18db',
        'resources/js/Components/AppShell/UserAccountMenu.vue' => '3f19fd436b22405f12bf2070565e50eb15268f7deb1eeb7160afa122eb1ffbaa',
        'resources/js/Components/PermissionMatrix.test.ts' => '5050666c6cdb788a74033ca42d7b9e0a09003faf5478992ce54af022f2a76839',
        'resources/js/Components/PermissionMatrix.vue' => '5376899d0142e95e8af4ceb218ea552d5495b733059e24e6165f7bc74b166a5f',
        'resources/js/Components/Products/SkuCombobox.test.ts' => 'fe5a4a4faf285ed257496aab9f183541df6ef3fab1cd708e410e9feca278c6b1',
        'resources/js/Components/Products/SkuCombobox.vue' => 'f2b5e07e5621b2c4e0184eaa308379d0185bad20efbd2de9bf0531fca7d9433e',
        'resources/js/Components/SearchableSelect.vue' => '9ece77235645ae4bd1c19bff0d01a408b5d333ae653b1bb34c1ce567f2939faf',
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
        'resources/js/Layouts/AuthenticatedLayout.vue' => '45a285e6b2093e80f700012a3a8a46c7d73124cdabccaac7b0d917dc888ac8fc',
        'resources/js/Pages/Architecture/Foundation.test.ts' => '68898785345d5cc641bcc8b3f165527a94c912e33c5f1941554a9a28aad97a1c',
        'resources/js/Pages/Architecture/Foundation.vue' => '0f3f96bd3ad9a5b68fa7fe5a78e20a1e0e92f4d2e6806ef30697fda64941aa70',
        'resources/js/Pages/Auth/Login.test.ts' => '445f3392872df2466f83736af7854a1a5c2e477198abea252c546e24141420c8',
        'resources/js/Pages/Auth/Login.vue' => '5595bb6472df208ce26f74a7e8ba51dd3d96d1421c1502517af97827a3d8b9db',
        'resources/js/Pages/Customers/Companies/Create.test.ts' => '2221b8d4e063e0615a97af14e6fbb738022fd44b55080a6fa6fa8c14d500e198',
        'resources/js/Pages/Customers/Companies/Create.vue' => '568e82d1d2587204b48319443d0d7958e2d053be901288ac02bdee2a58c68bb8',
        'resources/js/Pages/Customers/Companies/Edit.vue' => '7ffbcb6d19a43a59a0f27b37057f411c315644148cc57237e6f232ebc02b547f',
        'resources/js/Pages/Customers/Companies/Index.test.ts' => 'ab4d2c8f2e3f751daab6b8f980607251e31f67eb646539e593755386823713ab',
        'resources/js/Pages/Customers/Companies/Index.vue' => '7e7e164c6a1800d48f9440053c4238beffb932075f4b65053eb1a3e4f43d4add',
        'resources/js/Pages/Customers/Companies/Show.vue' => 'ec15baa7f74a287f7b8ab5b9c672da090b5031c0a8a055a9ccdcba5f28698b19',
        'resources/js/Pages/Dashboard/Index.test.ts' => '27c63e094fcdec28a0e5677508723e149c90a5130efb98d4448fa2b7f51b4ae4',
        'resources/js/Pages/Dashboard/Index.vue' => '0c28a768afcaa181b54ab01c4ec08457a04f7ed98e5ba3b52a041d98492bc2bb',
        'resources/js/Pages/Finance/OrderAnalysis.vue' => '96216f9ce4b67dd10bc4627c7e09703c1752c030110425509839d9aaab25d0aa',
        'resources/js/Pages/Guides/Edit.vue' => '47b31eb218c25505b2037e27460f99f52c80d42eee4057583d2ea680e9bd890b',
        'resources/js/Pages/Guides/Index.test.ts' => 'b751aca1f6cc636b969306643ad1278159dd3be28192c129df9033a62f92e0c9',
        'resources/js/Pages/Guides/Index.vue' => 'b4121443c078f0f386cbe204673386bf75d73fd187b9f642e61a05adabcc35ad',
        'resources/js/Pages/Guides/Releases/Edit.vue' => '4bd76aa0a3bdae2ba74a0d601259cda0c64a41b37f836b19faf2f14dd0fbaca2',
        'resources/js/Pages/Guides/Releases/Index.test.ts' => 'f87f3e583d97aadaf3fc77bd23a5793b48bf4bc66463112fc14d9fe11021ed2c',
        'resources/js/Pages/Guides/Releases/Index.vue' => 'b59f69aea90679b6786ee3ba36f0a7097c4557c8c00f6fbd871ea8f9c41b18f9',
        'resources/js/Pages/Guides/Releases/Show.vue' => '1186bb25382dcf06851b01f265753f5ce265a5503b28d9cf02cb00f8bf276eef',
        'resources/js/Pages/Guides/Show.test.ts' => '3dd81f33f5aa7303c7c09dcd9f7acc68ed57cf240025594a2322403fa670a1c0',
        'resources/js/Pages/Guides/Show.vue' => 'd8e6205a05d65bbb3e4aa823b762f4424114f65ad7c2550ac72374ae36d3124f',
        'resources/js/Pages/Identity/Account/Password.vue' => '515e95bbdad3b138f69f96676aeb176dd3554b048685c3e54da2897844ab2887',
        'resources/js/Pages/Identity/Account/Profile.vue' => 'cac0dfa2facdc852dfc9cc44d3897c6910eaa4d68abbd4edf6ff5091b741a85d',
        'resources/js/Pages/Identity/RolesPermissions/Index.test.ts' => 'c81fa140a3d75212813d17f95ccdf85da874f5118241031e06dbfb4ab9c062f5',
        'resources/js/Pages/Identity/RolesPermissions/Index.vue' => '5faa0e5bed62fb38d096a100964bb2c34ed7a855b10e43addd32c165bd9ebcd9',
        'resources/js/Pages/Identity/Users/Create.test.ts' => '56b4aa1d7673eb6ebfc4eed35a04381f459ee7a3717f7664ff094af64d5eb6d0',
        'resources/js/Pages/Identity/Users/Create.vue' => '48aa373766567b98a2ac27a2be7b1d1fe5342a94d25e1680a9ff6d25d49194e3',
        'resources/js/Pages/Identity/Users/Edit.test.ts' => 'c8653e756df55cbd1608ca9b0bcf4ab63b6973bfe1cb0f2e0ac6fbce7226d4e8',
        'resources/js/Pages/Identity/Users/Edit.vue' => '3d0774e7097e63648d01528f70b5243d960b7146a8ac0d6ae7708f6ad5011d3e',
        'resources/js/Pages/Identity/Users/Index.test.ts' => 'c2a508b3fb59d1f4725f86d658bfdcd162487477cae6c7f5e0db119b2f4f2cd0',
        'resources/js/Pages/Identity/Users/Index.vue' => '559f700abcd95b484fe834dbb0e5f871cc7301ff20d0e51f9c3c1179ea228e07',
        'resources/js/Pages/Inventory/Dashboard.vue' => 'b5f14d5b4a55c50366a7d33c07c91473afaab1ba590346c4214f9715b218556a',
        'resources/js/Pages/Inventory/InventoryBatchSync.test.ts' => '3c63a7717dcac8e4698db4fd922f69867c82ff9667b0c0f0f03b2b9045c5375f',
        'resources/js/Pages/Inventory/InventoryFoundation.test.ts' => 'cba1e89b5168dd738eb84741268ff360d86e2e3fa592e5e87cfa3f4ed805456b',
        'resources/js/Pages/Inventory/Products.vue' => 'ac82349fdc897f5000f2d8adaefab53daa8e13635490e6ae03ee210946f0efc7',
        'resources/js/Pages/Inventory/Sync.vue' => '3ec525e92c95252991a05999a539eab3d301a5d13e4c9512cde7a4249b8f044a',
        'resources/js/Pages/Inventory/WarehouseCreate.vue' => '62c6952525498d2a85c21e0e79531a016f0b5f759ad5e054408f91297a157ad2',
        'resources/js/Pages/Inventory/WarehouseList.vue' => 'a16f1296fbc977d4b7b6f6244f9588a2a1f4c33f147dfeb5d326109bf8b69ce0',
        'resources/js/Pages/Inventory/WarehouseShow.vue' => '8f025956114bce455da66e30fd75fea150f191f21f470e1a1eb0fe5b8a512865',
        'resources/js/Pages/Inventory/components/InventorySkuCombobox.vue' => 'd2d7408ee79d6fe9b060c268cf076a33a6d30ce096cc1d56ab9d49767e1bd670',
        'resources/js/Pages/Kol/Collaborations.vue' => '47df1c8b474ca53469f12bbbf63353d4df71277b8c20eb517e2362e19cf1593f',
        'resources/js/Pages/Kol/Create.test.ts' => '2f0beacb9c7f6a6cec7ffc210c43a9147151e34455f7be1a81e6bad24d861d56',
        'resources/js/Pages/Kol/Create.vue' => '89af33716eb03b6e102279b8b31c6b5756b33129b9fda0851545b0475a2ac68f',
        'resources/js/Pages/Kol/Edit.test.ts' => 'ef1a872ec59cd741a2518f3a466c753040d9002bf4580ef042d313dd5d692b9e',
        'resources/js/Pages/Kol/Edit.vue' => 'a5718b9a80c1ba62f95277152d2f2cc579eaec21b17b31a9ccf86e3f20320555',
        'resources/js/Pages/Kol/Index.vue' => '85fca824f80df112cad306b0b9807774ed1929b0d6026451dff96be37d2067ad',
        'resources/js/Pages/Kol/Overview.vue' => '8a37bafe02737e321426a177631327db4126504d7aaa7b2dedc3c0eb2da91ce9',
        'resources/js/Pages/Kol/PlaceholderPage.vue' => '17bcfd63e0f10267eccb24ff899ebb61d04265b1caceb5a35a7023c633fad595',
        'resources/js/Pages/Kol/Show.test.ts' => '4635acef351045c910c80d7e99bbd755c548d5750568f1089e585a8fdc3a38d9',
        'resources/js/Pages/Kol/Show.vue' => 'dea34c8cfcc2a9dd2cfac8359fed14ddfbbb34b773f96e1e9166c5fbcb3cd6cc',
        'resources/js/Pages/Logistics/Carriers/Index.test.ts' => '249b948ccd18672168875064728f3fc73902f3c70d1c6481bd16c2f74bc03574',
        'resources/js/Pages/Logistics/Carriers/Index.vue' => '4285fae56eec289f35620026938e49eb529cec41573bab96344fb5bfc9c9f921',
        'resources/js/Pages/Logistics/Carriers/Show.test.ts' => '274a1d05f1978e012ea68d3add9f785e5352bd053a4a1ac9c75d4a5f2f1cf583',
        'resources/js/Pages/Logistics/Carriers/Show.vue' => 'b1e3d928c6ba9be9da6e5907c60609532b2238ce967b6cce71c06b0ea2df343a',
        'resources/js/Pages/Logistics/Index.vue' => 'e7340d2ba412db52ff06e734bc2fcf9720e330443dafbb97049481e7b70f591a',
        'resources/js/Pages/Orders/Create.test.ts' => 'b39b0212f7ec61fb635eb1b3e70d44df7496e23c13aa04ccb491edb4228f7c12',
        'resources/js/Pages/Orders/Create.vue' => '86eca36f4b786ed15a80fe95e8088fcebe3cf09986e9126e82f891f7573474c7',
        'resources/js/Pages/Orders/Index.test.ts' => '86915c77870548f336b76febae683670c647db3aa0b8b033284b02f45ecf2296',
        'resources/js/Pages/Orders/Index.vue' => 'da57ec84a9e9d4bf54dccf859b44cf9da97aeb8649b8b38e068136d86816b509',
        'resources/js/Pages/Orders/Show.test.ts' => '89b380ae025bc29a31b5929563f90f228eaa70ceedb795b15b4637e22e37ee4b',
        'resources/js/Pages/Orders/Show.vue' => '3c022e12e36f035b9f43066c7b809ada9047a242b4cfa367b2b8ae6e04a23235',
        'resources/js/Pages/Products/Create.test.ts' => '5be67e88543a49632c107677ada316c3820ed0cb2b1d3d63c66604e3a22756e9',
        'resources/js/Pages/Products/Create.vue' => '58de31e6ad2b3fc976f9d9b785b69c51e5e9b5481e7d51157c5d9796bd39c216',
        'resources/js/Pages/Products/Dashboard.test.ts' => '94c55c75db32ab0ef154fda039b3735946898912939a8022ee1a4a3a59c97997',
        'resources/js/Pages/Products/Dashboard.vue' => 'ef523319db49a1d4bd970be5e7d700d7d5d1ed418f8e36c1e93e2a3477f309b9',
        'resources/js/Pages/Products/Edit.test.ts' => '4ec4575d2bc44646399de9a9ba2c2f3b32047835fd1295f18614d1663e803554',
        'resources/js/Pages/Products/Edit.vue' => '7662ef427ee1cbe94d5c5017499268a4c4b4a97d64239ff7cedd133cd46bf341',
        'resources/js/Pages/Products/Import.test.ts' => 'a9e749926038276a62094e946b44119353fcf5005b087956881b7c33faee133c',
        'resources/js/Pages/Products/Import.vue' => '3abe0367cae0aaffd141462b8c3d11ea06d80b6f093dbc707802d5e141fd3a13',
        'resources/js/Pages/Products/Index.test.ts' => '0dea6f018291aa009911da0cab40e199665955719118ad618dfcf5e2b630fdd6',
        'resources/js/Pages/Products/Index.vue' => 'd78d462ae0199d1ffd79e892f131fc215946859f8e9d8b22d4d31c6186640bc7',
        'resources/js/Pages/Settings/Index.test.ts' => '45cbba34d62289d3bd7c99bf05b9aa099a2875425c93ae8e0d4464619a60a825',
        'resources/js/Pages/Settings/Index.vue' => 'd931499dca9a7b0ae06408ea196d097a863992ba160d749b8e4a7520322d9038',
        'resources/js/Pages/Shell/ModuleOverview.vue' => '7c4148ed1570444f4b2cb5763a1a8cfac3c6f7b958b95a4f3d98fbf40b6ca02c',
        'resources/js/Pages/Shell/Placeholder.test.ts' => 'd0623492800b81b76650531eec2b979747d98daf34dcd4320ba00438529bb781',
        'resources/js/Pages/Shell/Placeholder.vue' => 'e18f588432b7d29a025f7618f977bdb7457536e8ae8028c2c343ce5c7b95472f',
        'resources/js/Pages/Sync/Exports.vue' => '932514b646b4ba57d800294d68bbcfd228ab69273139eb48e12b8940524fd6f4',
        'resources/js/Pages/Sync/Index.test.ts' => '974de1cb0004755edc7eace65444764bc2e710d8a1147ad74668ac1b67443970',
        'resources/js/Pages/Sync/Index.vue' => '36a0c4b48b5c09092e842356e3ca24a77d321b1cae414361e42a243d38dbed2a',
        'resources/js/Pages/Sync/Kol.vue' => '2e14cf9d2e6e45e012154ea9c16a97f5552044198a08e62ee20019b7ca9f7891',
        'resources/js/Pages/Sync/Orders.test.ts' => '0dca497aca6740187deb113bd2e3be023da7e93a36905f9abb15315b980356b1',
        'resources/js/Pages/Sync/Orders.vue' => 'ac820592eb1bc897e87e3b2926a8bf5b07e79c12dfb92f2ffe6293b6f63a2f4e',
        'resources/js/Pages/Sync/RunShow.test.ts' => '9197c15ef1b151072f645fd9c6a5231cfe599c8376bcbee862045056f67f1cc2',
        'resources/js/Pages/Sync/RunShow.vue' => '860b778527a11d6c9d2c2871b904dc922bf98ffbda472980fe4dee23cea3dd1c',
        'resources/js/Pages/Sync/Warehouses.vue' => '59a2e6ae75d572c26f80bef5500979b02b20fc84756fdfe73110ceefbb10d06b',
        'resources/js/Pages/Sync/WooCommerceInbound.vue' => '1c9800def13da3a7caad30dbc302e4e36d94a557e3ab43e4b109ee591dff9745',
        'resources/js/app.js' => '40994ac10f73e394b96d6e4742be37921fc38e477dccfdfe9e8ab90cd5e6d7d4',
        'resources/js/app.ts' => 'c667a849465fad821d6d08babe410c6d44bd7412629198522f1d408f373823b3',
        'resources/js/lib/globalFeedback.test.ts' => '63887c4a11ca91d7014df88987bf6ccbaf54be0c03dc334f78711fec3ff519d6',
        'resources/js/lib/globalFeedback.ts' => '54ba6e2214e691f2059e239571fb9f70a533b8b2dae7a6d8923f8a2ee43fbfc3',
        'resources/js/types/navigation.ts' => '265ec164e751beaa4c53045fa431850601840cbea6deab4e7a87de72e8538327',
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
            'app/Modules/Audit/Application/AuditActor.php',
            'app/Modules/Audit/Application/AuditService.php',
            'app/Modules/Audit/Application/AuditSource.php',
            'app/Modules/Audit/Application/AuditWriteException.php',
            'app/Modules/Audit/Application/Contracts/AuditRecorder.php',
            'app/Modules/Audit/Application/Contracts/AuditWriter.php',
            'app/Modules/Customers/Application/CompanyService.php',
            'app/Modules/Dashboard/Application/DashboardMetrics.php',
            'app/Modules/Finance/Application/OrderAnalysisService.php',
            'app/Modules/Guides/Application/KnowledgeBaseService.php',
            'app/Modules/Guides/Application/MarkdownContentRenderer.php',
            'app/Modules/Identity/Application/AuthorizationKeys.php',
            'app/Modules/Identity/Application/Contracts/AuditRecorder.php',
            'app/Modules/Identity/Application/DataScope.php',
            'app/Modules/Identity/Application/Exceptions/IdentityGuardException.php',
            'app/Modules/Identity/Application/PermissionCatalog.php',
            'app/Modules/Identity/Application/RolePermissionsService.php',
            'app/Modules/Identity/Application/RolePreset.php',
            'app/Modules/Identity/Application/UserService.php',
            'app/Modules/Inventory/Application/Contracts/InventoryAvailabilityReader.php',
            'app/Modules/Inventory/Application/Contracts/InventoryBatchSyncRepository.php',
            'app/Modules/Inventory/Application/Contracts/InventoryRepository.php',
            'app/Modules/Inventory/Application/InventoryBatchSyncService.php',
            'app/Modules/Inventory/Application/InventoryService.php',
            'app/Modules/Inventory/Application/Sync/ApiAdapterPlaceholder.php',
            'app/Modules/Inventory/Application/Sync/CsvAdapter.php',
            'app/Modules/Inventory/Application/Sync/NormalizedRow.php',
            'app/Modules/Inventory/Application/Sync/PasteAdapter.php',
            'app/Modules/Inventory/Application/Sync/RowNormalizer.php',
            'app/Modules/Inventory/Application/Sync/XlsxAdapter.php',
            'app/Modules/Kol/Application/KolScope.php',
            'app/Modules/Kol/Application/KolService.php',
            'app/Modules/Kol/Application/SavedViewService.php',
            'app/Modules/Logistics/Application/ShipmentService.php',
            'app/Modules/Orders/Application/Contracts/WooCommerceOrderGateway.php',
            'app/Modules/Orders/Application/Inbound/NormalizedInboundOrder.php',
            'app/Modules/Orders/Application/Inbound/WooCommerceInboundService.php',
            'app/Modules/Orders/Application/Inbound/WooCommerceOrderNormalizer.php',
            'app/Modules/Orders/Application/OrderKind.php',
            'app/Modules/Orders/Application/OrderKindBackfill.php',
            'app/Modules/Orders/Application/OrderService.php',
            'app/Modules/Orders/Application/OrderSourceType.php',
            'app/Modules/Orders/Application/OrderStatus.php',
            'app/Modules/Products/Application/Contracts/ProductRepository.php',
            'app/Modules/Products/Application/ProductImportResult.php',
            'app/Modules/Products/Application/ProductService.php',
            'app/Modules/Runtime/Application/Contracts/RedisRuntimeProbe.php',
            'app/Modules/Runtime/Application/QueueJobConvention.php',
            'app/Modules/Runtime/Application/QueueName.php',
            'app/Modules/Runtime/Application/RedisClientCapability.php',
            'app/Modules/Runtime/Application/RuntimeHealthService.php',
            'app/Modules/Shell/Application/ErpShellNavigation.php',
            'app/Modules/Shell/Application/LegacyErpNavigationPaths.php',
            'app/Modules/Sync/Application/Contracts/SyncOrchestrationRepository.php',
            'app/Modules/Sync/Application/Contracts/SyncOutboxRepository.php',
            'app/Modules/Sync/Application/Contracts/SyncRunRepository.php',
            'app/Modules/Sync/Application/Contracts/WooCommerceTrackingGateway.php',
            'app/Modules/Sync/Application/ManualSyncService.php',
            'app/Modules/Sync/Application/ManualSyncStatus.php',
            'app/Modules/Sync/Application/SyncOrchestrationService.php',
            'app/Modules/Sync/Application/SyncOutboxService.php',
            'app/Modules/Sync/Application/SyncOutboxStatus.php',
            'app/Modules/Sync/Application/SyncOutboxWriteException.php',
            'app/Modules/Sync/Application/SyncRunItemStatus.php',
            'app/Modules/Sync/Application/SyncRunService.php',
            'app/Modules/Sync/Application/SyncRunStatus.php',
            'app/Modules/Sync/Application/WooCommerceTrackingConflictException.php',
            'app/Modules/Sync/Application/WooCommerceTrackingOutboundService.php',
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
        self::assertSame('62be9d4e01ffcb018b10cdf65e41997b280ad18d69c325320f424824f7160592', hash_file('sha256', $this->path('menu/APPROVED_MENU.json')));
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
            KolServiceProvider::class,
            OrdersServiceProvider::class,
            LogisticsServiceProvider::class,
            FinanceServiceProvider::class,
            GuidesServiceProvider::class,
            RuntimeServiceProvider::class,
            AuditServiceProvider::class,
            SyncServiceProvider::class,
            ManualSyncServiceProvider::class,
            ShellServiceProvider::class,
            DashboardServiceProvider::class,
            CustomersServiceProvider::class,
            ProductsServiceProvider::class,
            InventoryServiceProvider::class,
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
