# ERP Global Grid R4 - Logistics-first execution priority - 2026-11-10

Owner instruction: all 51 selected entries stay frozen; prioritize Logistics because daily staff use these views. Batch order overrides prior execution order, not original eligibility and risk classification.

Source: canonical scope docs/10_Planning_Roadmap/ERP_GLOBAL_GRID_R4_51_PAGE_SCOPE_20261110.md and current GitHub main. This scheduling decision does not authorize automatic Production rollout.

## Exact batches (13 batches, 3-5 entries each)

| Order | Batch | Priority | Selected scope IDs | Core compatibility gate |
|---:|---|---|---|---|
| 1 | L1 - 国内仓发货闭环 | P0 | #16, #18, #19 (3) | 七视图操作与现有权限不变；先只读投影、再做原按钮回归 |
| 2 | L2 - 海外仓与物流跟踪 | P0 | #17, #20, #21, #22 (4) | 逐仓验证 MorePlus/US-SM；不因换表自动重发 |
| 3 | L3 - 物流尾部与历史 | P0 | #23, #24, #25, #26 (4) | 历史导入保留模板预检与确认；承运商配置不变 |
| 4 | S1 - 订单同步闭环 | P1 | #40, #41, #44 (3) | 同步状态、重试与已通知仍由现有后端决定 |
| 5 | O1 - 订单入口统一 | P1 | #1, #2, #3, #4, #5 (5) | 四个订单入口共用 OrderController@index；逐角色验收 |
| 6 | I1 - 库存仓库 | P2 | #11, #12, #13, #14, #15 (5) | 仓库详情只迁移主库存大表，其他控件不变 |
| 7 | P1 - 产品 / SKU / 采购 | P2 | #6, #7, #8, #9, #10 (5) | 旧试点不重做；SKU Draft #673 的 Owner UAT 仍未完成 |
| 8 | S2 - 其他同步接口 | P3 | #42, #43, #45 (3) | 接口权限与已存在同步动作不变 |
| 9 | K1 - 网红日常数据 | P3 | #27, #28, #29, #30 (4) | 保留人员数据隔离 |
| 10 | K2 - 网红佣金和绑定 | P3 | #31, #32, #33, #34 (4) | 佣金审批付款权限不变 |
| 11 | K3 - 网红导入 / 激活 | P3 | #35, #36, #37 (3) | 保留预检、确认和审计 |
| 12 | F1 - 财务与汇率 | P4 | #38, #39, #46 (3) | 成本公式与汇率编辑权限不变 |
| 13 | C1 - 客户 / 系统 / 营销 | P4 | #47, #48, #49, #50, #51 (5) | 用户角色、客户档案和流量图表不改业务逻辑 |

## Full exact execution ordering

| Priority order | Scope # | URL path | Selected view | Batch | Original risk |
|---:|---:|---|---|---|---|
| 1 | 16 | /logistics/china | 国内发货七视图 | L1 | 高 |
| 2 | 18 | /logistics/wait-stock | 待分配 / 待库存确认 | L1 | 高 |
| 3 | 19 | /logistics/review | 物流复核单 | L1 | 高 |
| 4 | 17 | /logistics/oversea/{warehouse?} (selected base URL: /logistics/oversea) | 海外仓发货清单 | L2 | 高 |
| 5 | 20 | /logistics/awaiting-tracking | 待回填物流单号 | L2 | 中 |
| 6 | 21 | /logistics/in-transit | 运输中订单 | L2 | 低 |
| 7 | 22 | /logistics/exceptions | 异常物流订单 | L2 | 中 |
| 8 | 23 | /logistics/cancelled | 已取消物流订单 | L3 | 低 |
| 9 | 24 | /logistics/completed | 已完结物流订单 | L3 | 低 |
| 10 | 25 | /logistics/carriers | 承运商清单 | L3 | 低 |
| 11 | 26 | /logistics/historical-import | 历史物流导入 | L3 | 高 |
| 12 | 40 | /sync | 同步中心清单 | S1 | 中 |
| 13 | 41 | /sync/orders | 订单同步 | S1 | 高 |
| 14 | 44 | /sync/woocommerce | WooCommerce 同步 | S1 | 中 |
| 15 | 1 | /orders | 全部订单总表 | O1 | 试点 |
| 16 | 2 | /customers/orders | 客户订单 | O1 | 中 |
| 17 | 3 | /kol/orders | 网红订单 | O1 | 中 |
| 18 | 4 | /orders/procurement | 外采订单 | O1 | 中 |
| 19 | 5 | /orders/archive | 归档订单 | O1 | 低 |
| 20 | 11 | /inventory/list | 仓库清单 | I1 | 低 |
| 21 | 12 | /inventory/overdue | 90 天超期库存 | I1 | 低 |
| 22 | 13 | /inventory/sync | 库存同步记录 | I1 | 中 |
| 23 | 14 | /inventory/packaging | 包装与测量清单 | I1 | 中 |
| 24 | 15 | /inventory/{warehouse} | 仓库详情库存子表 | I1 | 高 |
| 25 | 6 | /products/list | 产品总表 | P1 | 试点 |
| 26 | 7 | /products/skus | SKU 清单 | P1 | 低 |
| 27 | 8 | /products/sku-aliases | SKU 别名管理 | P1 | 中 |
| 28 | 9 | /purchase | 采购订单 | P1 | 试点 |
| 29 | 10 | /inventory/products | 产品库存 | P1 | 试点 |
| 30 | 42 | /sync/warehouses | 仓库同步 | S2 | 中 |
| 31 | 43 | /sync/kol | 网红同步 | S2 | 中 |
| 32 | 45 | /sync/wpapi | WordPress API 同步 | S2 | 中 |
| 33 | 27 | /kol/library | 网红资料库 | K1 | 中 |
| 34 | 28 | /kol/collaborations | 合作记录 | K1 | 中 |
| 35 | 29 | /kol/content | 内容与发布 | K1 | 低 |
| 36 | 30 | /kol/affiliatewp | AffiliateWP 清单 | K1 | 中 |
| 37 | 31 | /kol/commissions | 佣金清单 | K2 | 中 |
| 38 | 32 | /kol/payouts | 佣金付款 | K2 | 高 |
| 39 | 33 | /kol/owner-mapping | 网红负责人映射 | K2 | 中 |
| 40 | 34 | /kol/affiliatewp/reconciliation | AffiliateWP 数据核对 | K2 | 中 |
| 41 | 35 | /kol/affiliatewp/binding-activation | 绑定激活记录 | K3 | 中 |
| 42 | 36 | /kol/import-preview | 网红导入预览 | K3 | 高 |
| 43 | 37 | /kol/import-activation | 网红导入激活 | K3 | 高 |
| 44 | 38 | /accounting | 财务总览明细 | F1 | 中 |
| 45 | 39 | /accounting/order-costs | 订单成本与利润清单 | F1 | 中 |
| 46 | 46 | /settings/fx | 汇率设置中的表格 | F1 | 中 |
| 47 | 47 | /customers/library | 客户资料库 | C1 | 中 |
| 48 | 48 | /customers/companies | 客户公司清单 | C1 | 低 |
| 49 | 49 | /marketing/subscribers | 营销订阅用户 | C1 | 低 |
| 50 | 50 | /settings/users | 员工用户管理 | C1 | 高 |
| 51 | 51 | /marketing/traffic | 流量分析明细表 | C1 | 低 |

## Logistics exclusion vs complete operational archive

Owner clarification for the upcoming pinned right-hand action buttons and left-side batch selection: these are **different statuses** with different accounting eligibility. See [ERP_R4_LOGISTICS_EXIT_VS_OPERATIONAL_ARCHIVE_20261110.md](ERP_R4_LOGISTICS_EXIT_VS_OPERATIONAL_ARCHIVE_20261110.md). This is a required backend gate for batch operations, NOT automatic Production enablement; L1 existing Grid UAT and its other UI/permission guarantees remain independent.

## Safeguards and rollout behavior

- Execute logistics L1, L2, L3 first. A safe readonly logistics-list canary may be used inside L1 to prove the adapter before enabling a high-impact operational table. Priority is not authorization for live shipping.
- China /logistics/china includes seven operational views with SKU/quantity/amount, today carrier Excel downloads and tracking upload. This is one selected path but requires at least seven display checks. Workbench toolbar, inline carrier/tracking saves, out-of-stock controls and website sync actions remain in the original implementation until safe parity is proven.
- Oversea is an optional Laravel GET route /logistics/oversea/{warehouse?}. Verify each warehouse separately and preserve review, MorePlus OMS ACK, idempotency and manual fallback. No real dispatch in tests.
- Awaiting-tracking, in-transit, exceptions, cancelled, completed and review share LogisticsController but have different authoritative filters, data shapes and permissions; do not merge their data in the browser.
- Historical import is a table-adjacent workflow; migrate only its main record list, retaining file upload, precheck and confirmation.
- Treat /sync/orders as P1 immediately after Logistics, preserving official website synchronization and conditional manual retry.
- Other 40 selected entries remain frozen. Existing Global Grid pilot progress and SKU Draft PR #673 are not discarded; Owner visual UAT remains required.
- Use the same GlobalGrid.vue with a thin typed adapter only where code duplication justifies it. One shared build and Grid regression per 3-5 related-entry Dev PR, plus endpoint, permission, and critical-button regressions per workflow. Legacy fallback stays enabled. Formal Production deployment needs separate Owner approval.
- The provisional 40-80h overall target remains conditional. Measure actual work on the first three distinct migrated list surfaces and re-estimate by family; do not cut safety checks to hit budget.

## Execution state at priority freeze

- L1-L3 logistics pages: prioritized, not yet implemented or approved in Owner UAT.
- SKU Draft PR #673: PHP 59/59, Chrome 4/4 and Github checks 5/5 PASS from earlier Dev validation; Owner visual UAT PENDING. Do not silently merge, deploy or cancel.
- Production: no Grid defaults or permissions changed by this document.
