# ERP Global Grid V1 · R4 locked scope · 51 selected routes

Owner confirmation: 2026-11-10. Source of truth: GitHub main 423aa54a17fa82f58c0f992c5a6f345c4a82c84c.
Scope freeze: exactly 51 selected route entries. This is an approved R4 migration scope, NOT approval to enable every employee in Production.

## Immutable migration contract

- Reuse resources/js/Components/DataGrid/GlobalGrid.vue, wrapping OmsGrid.vue and the existing AG Grid Community core. No replacement or independent duplicated engines.
- Replace only each selected main business-list table or explicitly selected detail subtable; do not force-convert unrelated detail-page mini tables, forms, audit snippets, charts or mobile cards.
- Built-in global keyword search queries ALL authorized backend records (server-side where dataset is pageable). Retain independent status/warehouse/identity filters; do not search only the current page.
- Server-authoritative bounded pagination (20/50/100 where supported), allowlisted backend sort/filter fields, proper counts and zero N+1; no unlimited all-row requests.
- Preserve exact front-end original order number, SKU line grouping, tracking/sync status, exports, import templates/preflight/confirm, data permissions, workflow revisions, financial calculations and audit contracts.
- No changes to existing fulfillment, warehouse allocations, approvals, payments, KOL scope, importer, Woo synchronization, finance or other business mutations; grid rows only invoke existing server endpoints and server permissions.
- Each migrated page has an explicit Dev-only pilot switch in the initial implementation and original legacy list fallback. Pre-existing Production owner-only pilot behavior remains unchanged; no unapproved company-wide Production switch.
- Existing global VI V1.1 layout/query/preference rules and BYL-ERP-GRID-STANDARD-V1.md stay mandatory.

## Frozen batches and estimated implementation/UAT labor

| Phase | Selected entries | Work | Estimated effort | Gate |
|---|---:|---|---:|---|
| R4-0 | 4 | 既有 OMS / Global Grid 试点统一、默认开关方案 | 4–8 h | Exact-head regression + Dev Chrome + Owner visual PASS |
| R4-A | 12 | 标准低风险列表，含 SKU、归档、超期库存与普通只读清单 | 12–24 h | Exact-head regression + Dev Chrome + Owner visual PASS |
| R4-B | 12 | 带查询、权限和领域筛选的日常业务列表 | 24–48 h | Exact-head regression + Dev Chrome + Owner visual PASS |
| R4-C | 12 | 财务 / 同步 / AffiliateWP 等集成明细、复杂子视图 | 30–60 h | Exact-head regression + Dev Chrome + Owner visual PASS |
| R4-D | 11 | 国内七视图、海外仓、复核、进口、付款及权限高风险业务 | 55–110 h | Exact-head regression + Dev Chrome + Owner visual PASS |

**Total: 125–250 engineering/UAT hours** across all 51 scopes; a preliminary range, NOT spent hours or a completion promise. Re-evaluate after measured first 2–3 real lists. Shared engine reuse and grouping of common routes may reduce total effort. Operational pages may increase high-risk regression cost.

## Route-by-route frozen checklist

| # | ERP route | List or subtable | Risk | Phase | Acceptance status |
|---:|---|---|---|---|---|
| 1 | /orders | 全部订单总表 | 试点 | R4-0 | LOCKED / NOT STARTED |
| 2 | /customers/orders | 客户订单 | 中 | R4-B | LOCKED / NOT STARTED |
| 3 | /kol/orders | 网红订单 | 中 | R4-B | LOCKED / NOT STARTED |
| 4 | /orders/procurement | 外采订单 | 中 | R4-B | LOCKED / NOT STARTED |
| 5 | /orders/archive | 归档订单 | 低 | R4-A | LOCKED / NOT STARTED |
| 6 | /products/list | 产品总表 | 试点 | R4-0 | LOCKED / NOT STARTED |
| 7 | /products/skus | SKU 清单 | 低 | R4-A | LOCKED / NOT STARTED |
| 8 | /products/sku-aliases | SKU 别名管理 | 中 | R4-B | LOCKED / NOT STARTED |
| 9 | /purchase | 采购订单 | 试点 | R4-0 | LOCKED / NOT STARTED |
| 10 | /inventory/products | 产品库存 | 试点 | R4-0 | LOCKED / NOT STARTED |
| 11 | /inventory/list | 仓库清单 | 低 | R4-A | LOCKED / NOT STARTED |
| 12 | /inventory/overdue | 90 天超期库存 | 低 | R4-A | LOCKED / NOT STARTED |
| 13 | /inventory/sync | 库存同步记录 | 中 | R4-B | LOCKED / NOT STARTED |
| 14 | /inventory/packaging | 包装与测量清单 | 中 | R4-B | LOCKED / NOT STARTED |
| 15 | /inventory/{warehouse} | 仓库详情库存子表 | 高 | R4-D | LOCKED / NOT STARTED |
| 16 | /logistics/china | 国内发货七视图 | 高 | R4-D | LOCKED / NOT STARTED |
| 17 | /logistics/oversea | 海外仓发货清单 | 高 | R4-D | LOCKED / NOT STARTED |
| 18 | /logistics/wait-stock | 待分配 / 待库存确认 | 高 | R4-D | LOCKED / NOT STARTED |
| 19 | /logistics/review | 物流复核单 | 高 | R4-D | LOCKED / NOT STARTED |
| 20 | /logistics/awaiting-tracking | 待回填物流单号 | 中 | R4-B | LOCKED / NOT STARTED |
| 21 | /logistics/in-transit | 运输中订单 | 低 | R4-A | LOCKED / NOT STARTED |
| 22 | /logistics/exceptions | 异常物流订单 | 中 | R4-B | LOCKED / NOT STARTED |
| 23 | /logistics/cancelled | 已取消物流订单 | 低 | R4-A | LOCKED / NOT STARTED |
| 24 | /logistics/completed | 已完结物流订单 | 低 | R4-A | LOCKED / NOT STARTED |
| 25 | /logistics/carriers | 承运商清单 | 低 | R4-A | LOCKED / NOT STARTED |
| 26 | /logistics/historical-import | 历史物流导入 | 高 | R4-D | LOCKED / NOT STARTED |
| 27 | /kol/library | 网红资料库 | 中 | R4-B | LOCKED / NOT STARTED |
| 28 | /kol/collaborations | 合作记录 | 中 | R4-B | LOCKED / NOT STARTED |
| 29 | /kol/content | 内容与发布 | 低 | R4-A | LOCKED / NOT STARTED |
| 30 | /kol/affiliatewp | AffiliateWP 清单 | 中 | R4-B | LOCKED / NOT STARTED |
| 31 | /kol/commissions | 佣金清单 | 中 | R4-C | LOCKED / NOT STARTED |
| 32 | /kol/payouts | 佣金付款 | 高 | R4-D | LOCKED / NOT STARTED |
| 33 | /kol/owner-mapping | 网红负责人映射 | 中 | R4-C | LOCKED / NOT STARTED |
| 34 | /kol/affiliatewp/reconciliation | AffiliateWP 数据核对 | 中 | R4-C | LOCKED / NOT STARTED |
| 35 | /kol/affiliatewp/binding-activation | 绑定激活记录 | 中 | R4-C | LOCKED / NOT STARTED |
| 36 | /kol/import-preview | 网红导入预览 | 高 | R4-D | LOCKED / NOT STARTED |
| 37 | /kol/import-activation | 网红导入激活 | 高 | R4-D | LOCKED / NOT STARTED |
| 38 | /accounting | 财务总览明细 | 中 | R4-C | LOCKED / NOT STARTED |
| 39 | /accounting/order-costs | 订单成本与利润清单 | 中 | R4-C | LOCKED / NOT STARTED |
| 40 | /sync | 同步中心清单 | 中 | R4-C | LOCKED / NOT STARTED |
| 41 | /sync/orders | 订单同步 | 高 | R4-D | LOCKED / NOT STARTED |
| 42 | /sync/warehouses | 仓库同步 | 中 | R4-C | LOCKED / NOT STARTED |
| 43 | /sync/kol | 网红同步 | 中 | R4-C | LOCKED / NOT STARTED |
| 44 | /sync/woocommerce | WooCommerce 同步 | 中 | R4-C | LOCKED / NOT STARTED |
| 45 | /sync/wpapi | WordPress API 同步 | 中 | R4-C | LOCKED / NOT STARTED |
| 46 | /settings/fx | 汇率设置中的表格 | 中 | R4-C | LOCKED / NOT STARTED |
| 47 | /customers/library | 客户资料库 | 中 | R4-B | LOCKED / NOT STARTED |
| 48 | /customers/companies | 客户公司清单 | 低 | R4-A | LOCKED / NOT STARTED |
| 49 | /marketing/subscribers | 营销订阅用户 | 低 | R4-A | LOCKED / NOT STARTED |
| 50 | /settings/users | 员工用户管理 | 高 | R4-D | LOCKED / NOT STARTED |
| 51 | /marketing/traffic | 流量分析明细表 | 低 | R4-A | LOCKED / NOT STARTED |

### Important route/visual boundary clarifications

- /orders, /customers/orders, /kol/orders and /orders/procurement share the Orders list component and must be verified as **four distinct user workflows**; do not count shared component modifications as four separate independent new engines.
- /products/list migrates its ordinary product list only; factory measurements/accessory task views remain business-specialized. Existing /purchase and /inventory/products retain approved Owner-only canary defaults.
- /inventory/{warehouse} is a warehouse DETAIL page; migrate a suitable main inventory subtable only, not its entire detail forms or smaller supporting tables.
- /logistics/china is one route with seven operational views; all seven need read-only display and write-action parity evidence, separate domestic carrier import/export assertions, and Dede/Kaven/Liang/Vita access safety where relevant.
- /logistics/oversea is one family of routes with multiple warehouses and MorePlus/US-SM operational integrations. Never trigger real OMS auto-ship in a Grid UI UAT; use isolated synthetic/mock state.
- /inventory/overdue includes a separate exclusion management section and XLSX export; the reporting list can migrate but the exclusion edit controls and Excel output must remain untouched.
- /kol/import-preview, /kol/import-activation, /logistics/historical-import, /settings/fx and /marketing/traffic contain previews/config panels or analytics; adapt only eligible list sections. A tiny detail table qualifies for an explicit documented lightweight exception without removing it from the 51-item selection.
- No migration of /orders/trash, /products/factory-parameters or /products/weight-evidence at this time: those three were NOT in the 51 selected entries.

## Each page acceptance and staged release

1. Freeze page source and URL, capture existing visible columns, query options, sort/fill, contextual button semantics and role authorization from latest main; no fabricated UI fields.
2. Reuse approved Global Grid V1 design; if changing layout beyond the frozen component, get Owner visual proposal PASS first.
3. Dev-only dual-render toggle + legacy fallback + account-scope namespace + exact backend server filters and safe link/action mapping.
4. Run focused PHP/Vue, TypeScript, production build and browser checks for full dataset search/pagination, original identity, account isolation and all existing domain operations.
5. Prepare logged-in US Mac Dev browser pages for Owner inspection; require Owner UAT PASS for each phase. Do not call PASS before Owner inspects.
6. Merge only after applicable CI; separate explicit Owner approval is needed for every formal Production release; Production defaults remain OFF until separately approved. Preserve previous release rollback.

## Execution next step

Start R4-A with /products/skus (already has backend search and pagination), followed by /inventory/list, /inventory/overdue, then remaining low-risk pages. All changes in separate scoped branches/PRs to avoid collision with concurrent MorePlus, domestic logistics and sync branches.

## Exclusions and stop conditions

- No data migration, no live write/readback on customer orders, no warehouse OMS dispatch, no permission widening.
- Stop a selected page and record specific blocker if its source is not a proper standalone list, required server filtering is missing, or the implementation would alter business truth/permissions; keep the selected item on roadmap instead of silently dropping it.
