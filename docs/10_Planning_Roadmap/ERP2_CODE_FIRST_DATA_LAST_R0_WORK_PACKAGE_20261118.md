# ERP2 Code First → Data Migration Last — R0 合同与执行工作包（2026-11-18）

> 状态：**IN PROGRESS / DOCS ONLY / DEV ONLY**。Owner 已于 2026-11-18 确认新的战略路线。本文件是 R0 可追踪工作包，**不是**已完成的 ERP2 全模块验收报告、Production 迁移指令或正式发布授权。

## 1. 冻结决策

**ERP2_CODE_FIRST_DATA_LAST_V1**

1. 原 ERP 持续承担生产订单、发货、库存、财务以及 Woo/OMS 等外部副作用。
2. ERP2 优先依据 GitHub `main` 的实际 Laravel 控制器、Service、Model、migration、授权规则和现有测试开发功能；新 Vben 页面与 API 必须保持一套 canonical 数据真相，禁止复制 Order、Shipment、Inventory、Finance 真相到第二张业务表。
3. ERP2 Dev 使用独立、可丢弃的本地 SQLite/MySQL 测试数据；测试账户只在 local/testing 使用，任何生产客户订单/联系人/支付资料不得进入开发环境。
4. **Code First 不等于仅凭静态代码宣告业务正确**。每个状态变迁必须由隔离数据的真实 Laravel HTTP/服务执行、权限负例、事务回读、审计、前台 E2E 验证；不能用主表筛选/截图代替实际发货队列。
5. 最后才规划全量迁移、重复演练、增量同步、差异核对、受控切换与可回滚窗口。真实数据不可逆操作和 Production release 均须 Owner 独立明确授权。
6. 唯一开发/UAT 设备按 Owner 最新指令为 **007windows**；不使用 China Mac Chrome、不重启 READY 设备及现有 Chrome。原 ERP Production 不修改。此文档工作包不启动业务写入。

## 2. GitHub 事实基线（2026-11-18 只读确认）

- 仓库：`beyourlovercom/erpbeyourlovercom`，默认分支 `main`，本工作包审计起点 **`eb8ed64e7790efbcc6271742c8beaac3eaa3b30b`**。
- `main/app/Modules/` 含 **24 个顶层条目**（其中 ModuleManifest.php 和 ModulesServiceProvider.php 是文件，业务目录为 22 个）；不是 24 个业务模块。主要目录：Orders、Logistics、Inventory、Finance、Kol、Products、Procurement、Customers、Sync、Identity、Shell、Audit 等。
- `main/database/migrations/` 列出 **178** 个迁移文件（仅文件目录计数，不代表已在 Production 完成 178 个迁移）。
- 现有原 ERP 契约参考：`docs/10_Planning_Roadmap/16_BYL_ERP_CANONICAL_ORDER_FINANCE_KOL_DATA_CONTRACT_V1.md`；`main` 的功能测试目录包括 Orders 24、Logistics 62、Finance 18、KOL 35 个顶层条目（目录计数，不等同于通过的测试数）。
- ERP2 Vben 源码 **不在当前 main 的 apps 目录**；它位于未合并的 **Draft PR #766** / `feat/erp-siteops-007-r0-orders-readonly-20261114`。该 PR 之前在 007windows 验收：原 Laravel Dev 独立生成 126 个订单、123 条有效主表记录，Owner/Staff 123、KOL 20/41，客户 82，Vben 客户/网红专属只读路由 PASS。不得把这些状态描述为 ERP2 main 已发布。
- 隔离 Laravel 原始三路由在 007windows 只读探针中成功：`/logistics/wait-stock` 7、`/logistics/china?view=review` 0、`/logistics/china?view=pending` 0、`/logistics/oversea?view=review` 16、`/logistics/oversea/us-moreplus?view=pending` 0。KOL 对这三种业务入口返回 403。**新 ERP2 前台尚未完成这三类独立业务数据投影**，空样本不能证明完整业务流程。
- 独立仓库最终 SKU 规则工作（`feat/warehouse-final-sku-unification-20261118`）视为外部并行依赖；迁移时必须核实其是否已合并/发布，不能将旧订单 item.sku 擅自当作 final SKU。
- 早期 `AI/CURRENT_CONTEXT.md`（2026-08-29）与 `docs/10_Planning_Roadmap/13_PROGRAM_ROADMAP_AND_HOURS.md`（2026-09-19）包含历史阶段快照；它们不是 2026-11-18 Production runtime 状态。任何历史“COMPLETE”必须用当前 `main` + 已部署 runtime + 实测证据重新核对。

## 3. 目标模型与逐模块工序

| 阶段 | 范围 | Code First 完成门槛 | 迁移前置要求 |
|---|---|---|---|
| R0 | 原系统代码/数据库/权限/测试矩阵及 ERP2 基线 | 模块所有权、合同、冻结 UI 基线、源版本、负例及测试工作包确认 | 不接触真实订单 |
| R1 | 订单总表、客户单、KOL 单、待分仓 | 全字段/分页/搜索/状态/拆单及 4 类角色隔离；原控制器契约测试 | 新业务页面预览需先 Owner 视觉 PASS |
| R2 | 国内/海外发货、审核/驳回、物流单号及重发 | 每个按钮匹配真实 Dev HTTP 路由，允许/拒绝操作分别验证数据库回读及审计，严禁 OMS/Woo 正式外呼 | 保留 manual US-SM 规则，禁自动 OMS CREATE |
| R3 | 产品、仓库、库存、最终 SKU、履约分仓 | SKU×仓映射/缺货/改仓/重发/子单一致，库存事务与锁定负例 | 与 Warehouse Final SKU 工作包完成依赖核对 |
| R4 | 包装、采购、算账、网红及其它运营模块 | 不创造第二订单/财务/库存真相；各模块单独契约+E2E | 高风险资金、外部服务一律模拟 |
| R5 | 全自动化、员工权限、30+3 Workspace Tabs、UI | 同 head PHPUnit/前端单测/typecheck/build/E2E PASS，6 员工授权场景，失败记录可复现；Owner Live UAT PASS | 未验收页面禁止标完成 |
| R6 | 迁移程序和隔离全量演练 | 幂等迁移、可重复执行、引用关系完整、数据量/金额/状态/Tracking 差异为 0 | 数据脱敏及最小权限 |
| R7 | 增量追平、真实数据迁移与切换 | 双阶段差异核对、暂停生产写入窗口、回滚计划及切换演练，Owner 单独 PASS | **尚未授权执行** |

## 4. R0 源代码契约矩阵（初版；不得以初版充当全部审计）

| 业务真相/边界 | 原 ERP 源目录 | 已找到的优先测试证据 | 下一项核对 |
|---|---|---|---|
| 订单主记录 / 编号 / 分单 | `app/Modules/Orders` | `tests/Feature/Orders/OrderIdentitySearchContractTest.php`, `OrderProgressV3CoreTest.php`, `DerivedOrderDetailsTest.php` | Model/schema、公开订单号、归档/子单关系、写入 HTTP Gate |
| 分仓 / 国内/海外发货 / Shipment | `app/Modules/Logistics` | `tests/Feature/Logistics/PendingAllocationEntryPolicyTest.php`, `DomesticBusinessReviewTest.php`, `OverseaWarehouseWorkbenchTest.php` | 三个真实控制器 + 状态机 + role+warehouse 权限 + 真实 Dev fixture |
| 库存与预留 | `app/Modules/Inventory` | `tests/Feature/InventoryReservationFoundationTest.php`, `InventoryFoundationM1Test.php` | SKU×Warehouse 事务、并发、缺货及同步锁 |
| 产品及 SKU | `app/Modules/Products` | `tests/Feature/Logistics/OutboundOrderSkuAliasRegressionTest.php` | 原始 SKU / 映射 SKU / 最终出库 SKU 3 概念区分 |
| 网红/网红订单 | `app/Modules/Kol` | `tests/Feature/Kol/KolFulfillmentBridgeTest.php`, `KolApprovalServiceTest.php` | 专员本人、管理员全量、AffiliateWP 边界 |
| 财务/运费/利润 | `app/Modules/Finance` | `tests/Feature/Finance/OrderProfitV1Test.php`, `MultiShipmentCostAggregationTest.php` | 汇率、成本及多子单尾程保持金额精度 |
| 采购/包装 | `app/Modules/Procurement`, Inventory/Logistics 相关模型 | `tests/Feature/ProcurementOrderBridgeTest.php`, `FulfillmentActualWeightEvidenceTest.php` | 工厂两张证据图、计费重、采购发货关联 |
| 身份/权限/设置 | `app/Modules/Identity`, `app/Modules/Shell` | `tests/Feature/AuthorizationFoundationTest.php`, `UserUiPreferenceTest.php` | 6 员工权限、per-user grid layout、Workspace Tabs 30/3 |

本矩阵仅标记“存在源代码或测试文件”；单测通过情况、Dev 兼容性和功能完整性仍须逐项实际运行。

## 4A. 字段级数据库契约（main@eb8ed64e 的基础迁移；后续增量迁移仍待审计）

| canonical 表 | 初始迁移已证实的字段/唯一约束 | ERP2 不变量 | 待确认的后续改动 |
|---|---|---|---|
| `orders` | `id`、`internal_order_number UNIQUE`、`external_order_number`、`source_type`、`order_status`、`owner_id`、`order_date` | `orders.id` 为唯一订单主键；前台展示独立业务订单号，不能使用内部 ID 替代 | 2026-09-01 `erp_order_id UNIQUE`、归档/状态/路由字段与后续 schema |
| `order_items` | `order_id` 外键、`sku`、`quantity`、`unit_price DECIMAL(18,2)`、`line_revenue DECIMAL(18,2)` | 商品行归属 Order；金额不可转浮点精度；初始 SKU 不是自动确定的最终仓库 SKU | `product_id`、`woocommerce_line_item_id`、final SKU 方案及其迁移状态 |
| `shipments` | `order_id UNIQUE`、`shipment_status`、`carrier`、`tracking_number`、`shipped_at` | 原始模型每个 `orders.id` 至多一个 Shipment；A/B/C 通过派生订单而非覆写父单发货证据 | warehouse、service、review/dispatch、补发状态与 OMS 只读尝试字段 |
| `orders` 派生关系 | 2026-09-11 `fulfillment_parent_id → orders.id` + `fulfillment_suffix`；`UNIQUE(parent,suffix)` | A/B/C 每个派生单有独立主键及去重后缀，父子查询可双向追溯 | 切分服务及最新归档重发行为 |
| `fulfillment_allocations` | `order_id UNIQUE`、`status`、`decision_source`、`manual_assignments JSON`、`plan_snapshot JSON` | 分仓事实与手工选择不能靠 UI 列值重新构造；迁移要保留关联 | routing_warehouse_code、shipment warehouse_id 的同步规则 |
| `warehouses` / `warehouse_inventory` | warehouse `code UNIQUE` / `slug UNIQUE`；库存 `UNIQUE(warehouse_id,product_id)`；`quantity_on_hand`、`quantity_reserved` | 库存按商品×仓库唯一，预留量不可与在库量混淆 | 实际出库 SKU 对应的仓库产品身份、仓库别名、预留账本 |
| `product_sku_aliases` | `product_id`、`alias_sku`、`match_key UNIQUE`、`source_type` | 该 **初始 SKU 别名表没有 warehouse 维度**；绝不能据此宣称已实现每仓最终 SKU | 并行 Final SKU PR 落地后重新审计 warehouse-specific mapping |
| `order_external_references` | `order_id`、`provider`、`reference`，初始 `UNIQUE(provider,reference)` | 外部 ID 匹配与幂等不能仅凭模糊业务单号 | 后续 provider connection_key/扩展与历史导入策略 |

**关键差异：** 早期书面契约把 `external_order_number` 作为显示编号，2026-09-01 数据库又新增 `erp_order_id`；ERP2 必须读取最新 `Order` 展示投影，不得把旧设计文档的字段约定硬编码成现在的最终编号规则。

上述引用均是 GitHub `main` 中已存在的 **基础迁移文件静态证据**；没有运行 `migrate:status` 或完整 178 文件归并分析，故尚不能宣告 Production 最终列清单或 Schema PASS。

## 5. 最小测试场景（在独立 Dev 数据库生成）

- 正常客户单，KOL 专员本人单、KOL 管理员可见单、无权限跨账户单。
- 待分仓→分仓→国内审核→待发货→运单录入→已发货；国内审核缺席时物流录单仍被允许，但角色权限不绕过。
- 待分仓→海外审核→各仓待发货；改仓同时修正路由仓与 Shipment 归属；拒绝及撤回回到正确队列。
- A/B/C 子单在订单详情、发货队列、归档再发货和财务投影保持唯一关联。
- `BY489A-Lubes` 和不同仓库 Pillow 颜色别名：以 canonical 产品+仓库映射决定最终出库 SKU；缺映射明确失败，不能猜测或使用原始 SKU 冒充最终值。
- 取消/缺货/已归档/异常/重发/无 Tracking/重复 Tracking/重复 Warehouse provider request；重复操作幂等且不得产生外部订单。
- 每次迁移功能必须覆盖正向成功、权限拒绝、状态不允许、写入失败回滚四类检查，数据库读回与审计证据同头验证。

## 6. 数据迁移最后阶段的硬门槛

1. 核对原 `main` 与实际已发布 runtime 的 migration/schema 差异，冻结源版本和 cutover 时间点。
2. `orders.id`、前台订单号、外部 provider reference、order items、shipments、reservation、tracking、finance、A/B/C 子单的关系完整且能双向抽样回查。
3. 全量计数、金额/币种合计、订单状态分布、仓库路由、SKU 映射、Tracking、库存和财务关键指标按预定规则核对；无解释差异时 **HOLD**。不以“只剩几个异常”放宽门槛。
4. 增量同步必须水位可追踪、幂等、可重复执行；在批准切换前 ERP2 不向 Woo/OMS/仓库写入。
5. 至少一次可重复的迁移恢复演练，明确运行责任、备份验证、回滚耗时、冻结写入窗口；真实数据切换必须另行取得 Owner 明确授权。

## 7. R0 工作包边界、交付及验收

- **WORK_PACKAGE_ID:** `ERP2-CODE-FIRST-R0-CONTRACT-20261118`。
- **EXECUTION_PROVIDER:** `GPT_WEB`（仅本次文档/契约清单），后续广泛跨模块代码改造需独立 Work Package 和适配实施 Provider。
- **VERIFICATION_TIER:** `DAILY`（文档阶段）；后续 DB/权限/生产候选属于 `HIGH_RISK`。
- **BASELINE:** main@eb8ed64e7790efbcc6271742c8beaac3eaa3b30b。
- **DEPENDENCIES:** Draft PR #766 提供 ERP2 的未合并 Dev 客户/KOL 基线；Warehouse Final SKU 开发分支作为后续依赖，不纳入本 R0 文档改动。
- **DEPENDENCY_TYPE:** EXTERNAL（未来代码工作包须明确是否基于已合并的 #766 或 Owner 批准的 stacked PR）。
- **DEPENDENCY_STATUS:** PENDING；R0 文档独立不依赖未合并代码。
- **PARALLEL_SAFE:** YES（docs-only，独立 branch，不修改 #766 工作树及任何共享前端）。
- **PROHIBITED:** Production 读取/写入订单、生产发布、真实订单迁移、关闭/重启现有 UAT 端口、覆盖已 PASS 的 Vben dist、自动调用 OMS。
- **ACCEPTANCE:** Owner 审阅合同矩阵与路线；进一步完成当前 main 全量模块+表+路由覆盖登记、R1 视觉提案与独立测试设计，R0 才能称为 COMPLETE。
- **CURRENT RESULT:** 初步 GitHub 目录及现有测试入口盘点，**R0 尚未 COMPLETE**。没有运行测试或声明后续模块 PASS。
- **CI/VALIDATION:** 本文档仅仓库只读证据和 Markdown 路径/状态检查；任何代码实施都需在准确 head 上运行对应 PHPUnit、Vitest、typecheck、build 和 007windows E2E。
- **MERGE:** 本 PR 暂保持 Draft，基线对账与 Owner 审阅未完成；不合并 main，也不部署 Production。

## PROJECT POSITION
PROGRAM_ROADMAP: 原 ERP Architecture/Orders/Product/Inventory/Logistics/KOL/Finance 等运行中 → ERP2 R0 契约 → R1 Orders → R2 Logistics → R3 Product/Inventory/SKU → R4 Packaging/Procurement/Finance/KOL → R5 回归/员工 UAT → R6 迁移演练 → R7 增量/切换（Owner Gate）；历史完整版见文档 13，ERP2 计划为独立迁移工作流。
CURRENT_TRACK_PHASE_MAP: R0 IN PROGRESS；R1–R5 WAIT；R6–R7 WAIT/未获数据操作授权。
COMPLETED_PHASES: 原 ERP 若干已发布基线；ERP2 Dev 登录/权限及第一批订单只读页面验收见 Draft PR #766（非 main 发布）。
CURRENT_PHASE: ERP2 Code First R0。
CURRENT_WORK_PACKAGE: ERP2-CODE-FIRST-R0-CONTRACT-20261118。
PACKAGE_STATUS: IN_PROGRESS / DOCS_ONLY / DRAFT。
WAITING_PHASES: R1–R7。
BASELINE: main@eb8ed64e7790efbcc6271742c8beaac3eaa3b30b；ERP2 #766 Draft，未合并。
GITHUB_STATE: `main` 不含 `apps/erp-siteops-vben`；本 R0 独立文档分支 Draft PR。
SOURCE_CONFLICTS: 历史 AI/CURRENT_CONTEXT 2026-08-29 与文档13 2026-09-19 不能代表当前 ERP Production；待运行时核对。旧数据库 SKU 字段与新 final SKU 契约未同步；Draft #766 不能当 main 已实现。
GLOBAL_GATE: Owner 新路线已确认；R1 UI 实施前需 OWNER_VISUAL_PASS，正式数据与部署均独立审批。
PRODUCTION_DEPLOYMENT_STATUS: NO（本 R0 文档不部署；未核对当日现有 ERP Production 实际版本）。
NEXT_DEPENDENCY: 继续 R0 完整 schema / route / permission 盘点，R1 视觉方案 Owner PASS 后才能实施 UI。
EVIDENCE_AS_OF: 2026-11-18 GitHub contents/branch/PR 与 007windows 独立探针记录。

## ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 10 有效工程小时（范围 7–12h），R0 整体规划值；不是已实际完成时数。
KNOWN_CURRENT_TRACK_REMAINING: R1–R7 早期粗估 144–232h，范围可能重叠，尚未冻结，禁止视为精确剩余。
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE（ERP2 依赖 #766 未合并及视觉/权限门槛）。
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE（历史文档 13 有独立原 ERP MVP1 数字，不能直接转成 ERP2 工时）。
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE（待原 ERP 与 ERP2 范围去重）。
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE。
MVP1_REMAINING: UNKNOWN WITH EVIDENCE。
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE。
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE。
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE（待 R0 全模块盘点）。
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE（无已接受 Work Log ledger）。
ESTIMATE_CONFIDENCE: LOW-MEDIUM，初步目录级盘点。
ESTIMATE_AS_OF: 2026-11-18。
ESTIMATE_METHOD: 按代码/迁移/路由/权限/测试契约盘点与交付验证分解；不按聊天耗时计算。

## ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 1–2h，规划值 1.5h。
CORE_EXECUTION: 4–5h，规划值 4.5h。
TEST_AND_VALIDATION: 1–2h，规划值 1.5h。
REMEDIATION_BUFFER: 1–3h，规划值 2.5h。
ESTIMATED_EFFECTIVE_HOURS: 10h。
ESTIMATE_RANGE: 7–12h。
ESTIMATE_BASIS: R0 文档与源代码契约盘点，不包含后续业务模块实现。
OWNER_REVIEW: INCLUDED。
MERGE: EXCLUDED（本 R0 Draft 保留，待 Owner 审阅）。
MIGRATION: EXCLUDED。
MIGRATION_FILE_AUTHORING: EXCLUDED。
MIGRATION_EXECUTION: EXCLUDED。
UAT: EXCLUDED（本阶段仅文档，不涉及新 UI）。
DEPLOYMENT: EXCLUDED。
