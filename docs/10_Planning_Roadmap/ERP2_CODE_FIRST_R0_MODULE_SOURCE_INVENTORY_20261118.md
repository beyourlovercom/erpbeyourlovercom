# ERP2 Code First — R0 GitHub 模块目录 / Routes / Tests 证据索引（2026-11-18）

> **只读静态索引；非运行时验收，也非任何功能 PASS。** 仓库 `beyourlovercom/erpbeyourlovercom`，source = `main@eb8ed64e7790efbcc6271742c8beaac3eaa3b30b`。完整工作包：`ERP2_CODE_FIRST_DATA_LAST_R0_WORK_PACKAGE_20261118.md`。

## 1. 审计口径

- `app/Modules` 包含 **22 个业务模块目录**及另 2 个 `.php` 顶层文件；表格按 22 个目录逐一列示。
- Routes 栏是 `app/Modules/{模块}/Experience/Routes/` 的**直接文件名**；不存在该目录只表示此固定路径未找到，不代表此模块没有经全局/其它 provider 注册的路由。
- Tests 栏是 `tests/Feature/{模块}/` 的**直接条目数**（可能有子目录），不是全仓测试案例总数、不是执行数、也不是通过数。目录不存在不等于没有测试（可能位于 `tests/Feature` 顶层或其它模块）。
- Laravel `Route::` 映射、middleware、实际 Controller/Application/Infrastructure 绑定、最终有效数据库 schema 与权限矩阵仍需后续基于**准确 HEAD + 隔离运行时**逐条核验。

## 2. 全量模块目录索引

| Laravel 模块 | ERP2 优先阶段 | 固定路径 Routes 文件 | 固定路径 Feature Tests 顶层条目 |
|---|---|---|---:|
| `Audit` | R5 | `web.php` | 2 |
| `Backup` | R5 | `web.php` | 7 |
| `BusinessProjects` | R4/R5 | 该目录未找到 | 该目录未找到 |
| `Customers` | R1 | `web.php` | 5 |
| `Dashboard` | R5 | `web.php` | 该目录未找到 |
| `Finance` | R4 | `web.php` | 18 |
| `Guides` | R5 | `web.php` | 1 |
| `Identity` | R5 | `user-management.php`, `web.php` | 5 |
| `Inventory` | R3 | `web.php` | 18 |
| `Kol` | R1/R4 | `web.php` | 35 |
| `Logistics` | R2 | `oversea_manual.php`, `web.php` | 62 |
| `Marketing` | R4 | `web.php` | 6 |
| `NotificationCenter` | R5 | `web.php` | 该目录未找到 |
| `Orders` | R1 | `web.php` | 24 |
| `Procurement` | R4 | `web.php` | 该目录未找到 |
| `Products` | R3 | `web.php` | 25 |
| `Runtime` | R5 | `web.php` | 3 |
| `Search` | R5 | `web.php` | 1 |
| `Shell` | R5 | `web.php` | 该目录未找到 |
| `Support` | R5 | `web.php` | 1 |
| `Sync` | R5 | `web.php` | 6 |
| `Traffic` | R4 | `web.php` | 该目录未找到 |

## 3. R1–R3 必须追溯的实际入口

| 阶段 | 入口索引 | 必需的 Dev 隔离验收 |
|---|---|---|
| R1 订单与待分仓 | `Orders/Experience/Routes/web.php`、`Customers/Experience/Routes/web.php`、`Kol/Experience/Routes/web.php` | 真实 Order/Item/身份连接；订单号、状态、筛选、分页、本人权限；待分仓不能用总表的假筛选替代 |
| R2 国内/海外发货 | `Logistics/Experience/Routes/web.php`、`oversea_manual.php` | 国内审核/待发货、海外审核/各仓待发货分别有专属读投影；写按钮先映射到隔离 Laravel 操作及权限/事务；manual US-SM 不自动出仓 |
| R3 产品/库存/final SKU | `Products/Experience/Routes/web.php`、`Inventory/Experience/Routes/web.php` | 基础 SKU、仓库专属最终 SKU、Warehouse ID、分仓实际出库信息唯一映射；缺映射失败；库存预留/并发负例 |

## 4. 需要阅读和执行的源测试入口（示例，不代表测试通过）

- **Orders:** `tests/Feature/Orders/DerivedOrderDetailsTest.php`；`tests/Feature/Orders/KolLogisticsReadOnlyTest.php`；`tests/Feature/Orders/KolOrderOwnerScopeTest.php`；`tests/Feature/Orders/OrderAdjacentNavigationTest.php`；`tests/Feature/Orders/OrderArchivePermissionPresetTest.php`；`tests/Feature/Orders/OrderCoreStatusV1Test.php`；`tests/Feature/Orders/OrderIdentitySearchContractTest.php`
- **Logistics:** `tests/Feature/Logistics/AfterShipBrandedPageDeliveryEvidenceGatewayTest.php`；`tests/Feature/Logistics/AfterShipDeliveryEvidenceGatewayTest.php`；`tests/Feature/Logistics/AutomaticAllocationV1Test.php`；`tests/Feature/Logistics/AutomaticFulfillmentReadinessTest.php`；`tests/Feature/Logistics/CarrierBillingImportTest.php`；`tests/Feature/Logistics/CarrierMasterV2Test.php`；`tests/Feature/Logistics/CarrierWorkspaceTest.php`
- **Inventory:** `tests/Feature/Inventory/BlueLabelPackageV1Test.php`；`tests/Feature/Inventory/BlueLabelWorkerProtocolTest.php`；`tests/Feature/Inventory/BlueLabelWorkerReliabilityV1Test.php`；`tests/Feature/Inventory/FactoryDemandThresholdTest.php`；`tests/Feature/Inventory/GlobalSkuStockIsolationTest.php`；`tests/Feature/Inventory/MorePlusInventorySyncTest.php`；`tests/Feature/Inventory/PackagingMeasurementEvidenceTest.php`
- **Products:** `tests/Feature/Products/Barcode69EndpointTest.php`；`tests/Feature/Products/ChinaSmallPacketBarcode69ReaderTest.php`；`tests/Feature/Products/Product360InlineParametersTest.php`；`tests/Feature/Products/Product360PackagingTabIntegrationTest.php`；`tests/Feature/Products/Product360SkuAliasManagementTest.php`；`tests/Feature/Products/Product360Test.php`；`tests/Feature/Products/ProductBarcode69Test.php`
- **Kol:** `tests/Feature/Kol/AffiliateAccountPreviewTest.php`；`tests/Feature/Kol/AffiliateBindingTest.php`；`tests/Feature/Kol/AffiliateSnapshotSyncTest.php`；`tests/Feature/Kol/AffiliateWpWorkspaceTest.php`；`tests/Feature/Kol/HmacV1SignerTest.php`；`tests/Feature/Kol/HttpAffiliateWpGatewayTest.php`；`tests/Feature/Kol/HttpWooCouponSettingsGatewayTest.php`
- **Finance:** `tests/Feature/Finance/AccountingContinuousDeltaTest.php`；`tests/Feature/Finance/ActualLastMileMaterializationTest.php`；`tests/Feature/Finance/ConfirmedProductCostSourceTest.php`；`tests/Feature/Finance/CostMaterializationTest.php`；`tests/Feature/Finance/EcbFxServiceTest.php`；`tests/Feature/Finance/FxSettingsTest.php`；`tests/Feature/Finance/GatewayEconomicsV1Test.php`
- **Identity:** `tests/Feature/Identity/OriginalErpIdentityEndpointTest.php`；`tests/Feature/Identity/PermissionMatrixTest.php`；`tests/Feature/Identity/SelfAccountManagementTest.php`；`tests/Feature/Identity/StaffAccessReadinessAuditTest.php`；`tests/Feature/Identity/UserManagementTest.php`
- **Procurement:** 对应目录未找到

## 5. 开发/发布硬门槛

1. 本索引作为 R0 静态源证据，只能用于确定 R1–R5 审计范围，不能宣告自动化测试或 007windows ERP2 UI UAT PASS。
2. ERP2 #766 是未合并 Draft、不是 `main`；新前端开发工作包必须明确其依赖提交与集成策略。
3. 每个真实入口须补 Controller → Service → Canonical Model → middleware/permission → schema → PHPUnit/浏览器 E2E 的链路证明，保留正/负例、唯一键、事务和审计。
4. Dev 独立合成数据；Production 订单不读入 ERP2、不迁移、不写入，Woo/OMS/仓库真实动作全部禁用。R6/R7 最后迁移需独立 Owner 授权。
5. 新 UI 实施必须先有 Owner 视觉预览 PASS；Draft PR 不可因文件和测试目录齐全而合并发布。
