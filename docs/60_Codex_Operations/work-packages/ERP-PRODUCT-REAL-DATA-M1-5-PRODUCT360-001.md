# ERP-PRODUCT-REAL-DATA-M1-5-PRODUCT360-001

STATUS: OWNER_UAT_PASS / M1.5A_OWNER_UAT_PASS / GIT_DELIVERY_AUTHORIZED
EXECUTION_PROVIDER: US_CODEX_CLI
REMEDIATION_PROVIDER: GPT_WEB+DC (SERIALIZED; no concurrent provider)
VERIFICATION_TIER: DAILY
OWNER_UAT_REQUIRED: YES
GIT_DELIVERY_AUTHORIZATION: OWNER_AUTHORIZED_COMMIT_PUSH_CREATE_PR
PRODUCTION_AUTHORIZATION: NOT_AUTHORIZED

## Goal

Deliver the Owner-approved Product360 read-only detail workspace at `/products/{id}` while preserving `/products/{id}/edit` as Product Master editing.
Product360 is a projection workspace only. It must aggregate existing canonical Product, Order, Inventory, Logistics, KOL and Audit truth without creating a second truth system.

## Frozen visual baseline

Top Hero:
- product image slot / canonical media only; show neutral placeholder when unavailable
- Product name
- SKU
- Product ID
- Woo external reference summary
- Source
- Status
- Product Type
- page actions: Edit Product, View Woo when a safe canonical URL exists, More

Tabs:
- 概览
- 销售
- 库存
- 物流
- 成本与海关
- Woo 信息
- SKU 别名
- 业务关联
- 审计

## Canonical data contract

Product Master authority:
- `products.id`, canonical SKU, name, type, source, status, prices, logistics fields, customs/cost fields

Woo authority:
- `product_external_references` from M1.4
- preserve legacy `products.woocommerce_product_id` only as compatibility evidence
- parent Product ref and Variation refs may belong to one canonical Product

SKU identity authority:
- canonical SKU + M1.2 SKU aliases

Logistics evidence authority:
- `product_weight_evidences` + adopted Product weight pointers from M1.3

Inventory authority:
- Inventory module warehouse/product stock truth
- show active canonical warehouses dynamically
- show On Hand / Reserved / Available / Incoming only from canonical inventory fields
- unknown inventory remains unknown; never coerce unknown to numeric zero

Sales / Order authority:
- customer Orders + Order Items where `order_items.product_id = products.id`
- 30-day / 90-day / lifetime units and order counts
- revenue remains grouped by original currency; no currency conversion in M1.5

Logistics / Shipment authority:
- canonical Shipment records linked through Orders containing the Product
- recent shipment projection is read-only

KOL authority:
- canonical KOL Collaboration + collaboration product rows
- recent collaborations are read-only Product relations

Audit authority:
- canonical Audit records for Product and Product-owned evidence/identity events
- actor/source labels must remain evidence-based

## Read projection architecture

Follow the existing Customer360 projection pattern:
- Orders module owns `ProductOrderProjection`
- Logistics module owns `ProductShipmentProjection`
- KOL module owns `ProductKolProjection`
- Audit module owns `ProductActivityProjection`
- Product controller consumes those Application projections plus InventoryAvailabilityReader
- Product360 must not import another module's Infrastructure models into Products Application
- no new Product360 persistence table
- no denormalized Product copies of Order/Inventory/Shipment/KOL facts

## Route / permission contract

- add `GET /products/{id}` named `products.show`
- route requires `auth + can:products.view`
- Edit action appears only for `products.operate`
- normal Product list/detail navigation should prefer Product360 Show, not Edit

## Workspace content contract

概览:
- Hero identity
- compact sales summary
- warehouse stock summary
- logistics/weight summary
- recent Orders / Shipments / KOL relations / Audit highlights

销售:
- sale price / regular price
- 30-day / 90-day / lifetime units sold and order counts
- revenue grouped by currency
- recent customer Orders with links

库存:
- one row/card per active canonical warehouse
- On Hand / Reserved / Available / Incoming
- explicit inventory freshness/state where available

物流:
- net weight and adopted evidence source
- shipping weight and adopted evidence source
- length / width / height
- volumetric-weight reference stays `—` until a canonical formula is separately frozen

成本与海关:
- production / packaging / purchase reference costs
- currency / cost note / origin / HS code

Woo 信息:
- provider / connection key / product and variation external refs
- no live Woo mutation
- View Woo only when a safe canonical connector URL can be derived; otherwise do not fabricate a URL

SKU 别名:
- canonical SKU
- aliases from M1.2
- read-only in Product360

业务关联:
- recent Orders
- recent Shipments
- recent KOL Collaborations
- all links target canonical ERP workspaces

审计:
- recent Product-related Audit records with action, actor/source and timestamp

## Product image boundary

M1.5 does not implement WordPress Product/Media sync.
- use canonical image/media only if it already exists in repository truth
- when no canonical image is available, render a production-quality neutral placeholder
- do not scrape Woo, infer media URLs, or add a second image authority
- M1.6 owns WordPress Product + Media inbound sync

## Out of scope

- WordPress Product live sync
- Product image/media ingestion
- Woo outbound writes
- new Product truth tables
- Inventory mutation or reservation

- Shipment mutation or fulfillment automation
- KOL mutation
- currency conversion
- historical snapshot rewrite
- Product Edit redesign
- M1.6 Media Sync
- Production deployment/migration

## Acceptance criteria

1. `/products/{id}` is the normal read-only Product360 workspace.
2. `/products/{id}/edit` remains Product Master editing.
3. `products.view` can open Product360 without `products.operate`.
4. Edit action is permission-safe.
5. Hero follows the approved visual baseline and does not create excess vertical space.
6. All 9 frozen tabs are present.
7. Product identity uses canonical Product truth.
8. Woo refs correctly display parent + multiple variation refs and connection keys.
9. SKU aliases are canonical read-only relations.
10. Inventory is warehouse-aware and unknown never becomes fake zero.
11. 30/90/lifetime sales are derived from customer Order Items by canonical product_id.
12. Multi-currency revenue is not silently summed across currencies.
13. Recent Orders link to canonical Order detail pages.
14. Recent Shipments link/project canonical Logistics truth.
15. Recent KOL collaborations link/project canonical KOL truth.
16. Product-related Audit is evidence-based and read-only.
17. Product image placeholder is used when canonical media is unavailable.
18. No new Product360 persistence/schema is introduced.
19. No M1.2/M1.3/M1.4 frozen behavior regresses.
20. No Product Edit visual regression beyond pre-existing baseline failures.

## Verification contract

Backend:
- Product360 route/permission/show payload tests
- ProductOrderProjection sales window + multi-currency tests
- ProductShipmentProjection tests
- ProductKolProjection tests
- ProductActivityProjection tests
- M1.2 SKU Alias regression
- M1.3 Weight Evidence regression
- M1.4 Product External References regression
- Order / Inventory / Fulfillment compatibility tests for touched projections

Frontend:
- Product360 Show focused tests for Hero, tabs and each projection surface
- permission-safe actions
- empty states / unknown inventory / multi-ref and multi-currency fixtures
- Product List navigation regression
- Product Edit existing suite compared against current main baseline
- typecheck
- production Vite build

Architecture:
- ModuleArchitectureTest current-main baseline comparison
- new Architecture regression delta must be zero
- update only registry entries/hashes for files legitimately added/changed by this WP

Runtime / Owner UAT:
- US Mac isolated Local/UAT
- real session auth; no bypass
- local-only `admin/password` may be provisioned per repository gate when a fresh UAT DB is used

## Minimum Owner UAT scenarios

1. Woo canonical Product with parent + Variation refs opens Product360.
2. Hero is compact and approved; image placeholder does not dominate the page.
3. Edit remains separate and reachable only to an operator.
4. Sales tab shows real 30/90/lifetime metrics and recent Orders.
5. Inventory tab shows current canonical warehouses and correct unknown/zero distinction.
6. Logistics tab shows M1.3 weights/evidence and dimensions without inventing volumetric weight.
7. Woo tab shows Product/Variation refs grouped by connection.
8. SKU Alias tab shows canonical SKU + aliases read-only.
9. Business Relations shows real Order / Shipment / KOL links without duplicated truth.
10. Audit tab shows evidence-based Product activity.
11. Manual Product / no-Woo Product renders cleanly with empty Woo state.
12. Sparse Product with no relations renders production-quality empty states.


## M1.5A Owner-approved addendum — KOL Shipping Summary

Owner render gate: PASS before implementation.

Approved overview upgrade:
- add `网红发货摘要` beside `销售摘要` with 30-day / 90-day / lifetime shipped units and distinct shipment counts
- add compact `销售 / 网红发货 对比` strip with lifetime sales units, lifetime KOL shipped units, and derived `发货 / 销售比例`
- ratio is display-only derivation; when lifetime sales units are zero it renders `—`, never Infinity or a fabricated conversion rate
- lower overview row remains compact Logistics + Inventory summary; detailed relations stay in `业务关联`

Canonical KOL shipping truth:
- only canonical Orders where `orders.order_kind = kol_fulfillment`
- only canonical Shipments where `shipment_status = shipped` and `shipped_at` is present
- product identity is `order_items.product_id = products.id`
- shipped units are `SUM(order_items.quantity)`
- shipment count is distinct canonical Shipment count
- 30/90-day windows use actual `shipments.shipped_at`, never collaboration creation/planned dates
- a Collaboration product row or a pending fulfillment order is not counted as shipped
- no new persistence/schema and no KOL/Order/Shipment mutation

## Git / release authority

Implementation, local verification and Owner-UAT preparation are authorized by Owner instruction to enter M1.5.
Commit, push, PR, merge, deployment, Production migration, tag/release and worktree cleanup remain separately gated.

## Dependencies

BASELINE: `origin/main@0a9ab219ac7fa80dfcde1958350503d8206c92d8`
DEPENDENCIES: Product M1.2 + M1.3 + M1.4 merged; canonical Order/Inventory/Logistics/KOL/Audit modules already merged
DEPENDENCY_TYPE: MERGED_PR
DEPENDENCY_STATUS: SATISFIED
PARALLEL_SAFE: YES, dedicated Product360 worktree; no same-file parallel implementation permitted

## Provider rationale

The Product360 visual, data authorities, route split and acceptance behavior are frozen. The work is a deterministic read-projection workspace spanning several established modules, with no new schema or unresolved business design. Under Execution Role Model v1.3 this is assigned to US Codex CLI rather than starting a new Claude session.

PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate → MVP1 Existing Operations / Tencent Docs Replacement → ERP-INQUIRY-M1 B2B Inquiry Foundation → ERP-INQUIRY-M1 Local Milestone UAT → milestone-gated Remote Release → MVP1.1 WooCommerce Inbound → MVP1.2 Single Tracking Outbound → MVP1.3 Sync Orchestration → Product / Media → WordPress Product and Image Sync → Content / SEO → Posts / Pages → GA4 / GSC / Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS and Social Providers → Inventory and Procurement → Accounting / ROI Expansion → Shopify and Additional Integrations → Production Hardening / Expansion
CURRENT_TRACK_PHASE_MAP: Product M1.1 Canonical Bootstrap [CLOSED] → M1.2 SKU Alias/Reconciliation [CLOSED] → M1.3 Logistics Weight Evidence [CLOSED] → M1.4 Woo External References [CLOSED] → M1.5 Product360 [CURRENT] → M1.6 WordPress Product + Media Inbound Sync [WAITING]
COMPLETED_PHASES: Product M1.1, M1.2, M1.3, M1.4
CURRENT_PHASE: Product M1.5 — Product360 Detail Workspace
CURRENT_WORK_PACKAGE: ERP-PRODUCT-REAL-DATA-M1-5-PRODUCT360-001
PACKAGE_STATUS: IMPLEMENTATION_AUTHORIZED / IN_PROGRESS
WAITING_PHASES: Product M1.6 WordPress Product + Media Inbound Sync
BASELINE: origin/main@0a9ab219ac7fa80dfcde1958350503d8206c92d8
GITHUB_STATE: M1.4 PR #156 merged; M1.5 local branch/worktree created; M1.5 Git delivery not authorized
SOURCE_CONFLICTS: AI/CURRENT_CONTEXT.md is stale at 2026-08-29 / PR #110; latest fetched GitHub main and Owner-approved Product OS sequence govern mutable status. No Product360 design conflict found.
GLOBAL_GATE: Owner UAT + full verification + separate Git delivery/merge authorization
PRODUCTION_DEPLOYMENT_STATUS: NO WITH EVIDENCE; M1.5 production deployment is not authorized
NEXT_DEPENDENCY: implement Product360 projections + Show workspace, then US-local verification and Owner UAT
EVIDENCE_AS_OF: 2026-09-02

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 5.5 effective hours planning value; 4.0-8.25h range
KNOWN_CURRENT_TRACK_REMAINING: UNKNOWN WITH EVIDENCE; M1.5 is frozen, M1.6 implementation package/hours are not yet frozen
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE; this WP requires Architecture regression delta 0 and does not own global legacy APP-001 cleanup
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE; current Product OS successor work is beyond the stale CURRENT_CONTEXT estimate snapshot
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE; not recalculated in this WP
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE; not recalculated in this WP
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; accepted cross-program actual ledger not reconciled here
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE; M1.6 not yet frozen
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE; not recalculated in this bounded Product WP
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE; program-wide remaining arithmetic is outside this WP
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; no accepted Product-track Work Log ledger loaded into this WP
ESTIMATE_CONFIDENCE: MEDIUM-HIGH for M1.5 only
ESTIMATE_AS_OF: 2026-09-02
ESTIMATE_METHOD: bottom-up by frozen projection, Vue workspace, regression verification and Owner-UAT preparation; excludes Git delivery/merge/deploy

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.5h planning; 0.25-0.75h range
CORE_EXECUTION: 3.5h planning; 2.5-5.0h range
TEST_AND_VALIDATION: 1.0h planning; 0.75-1.5h range
REMEDIATION_BUFFER: 0.5h planning; 0.5-1.0h range
ESTIMATED_EFFECTIVE_HOURS: 5.5h
ESTIMATE_RANGE: 4.0-8.25h
ESTIMATE_BASIS: frozen Product360 visual/data contract; established Customer360 projection precedent; no schema; cross-module read projections + one Vue Show workspace
OWNER_REVIEW: INCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: INCLUDED
DEPLOYMENT: EXCLUDED

## Final local verification evidence

### M1.5A KOL Shipping Summary addendum
- Pre-development render gate: OWNER PASS
- Canonical shipped truth: `orders.order_kind=kol_fulfillment` + `shipments.shipment_status=shipped` + non-null `shipments.shipped_at` + `order_items.product_id`
- Product360 backend focused: 8 tests / 57 assertions PASS
- Product360 frontend focused: 29/29 PASS
- Relevant Products + KOL Fulfillment + Logistics backend: 144 tests / 512 assertions PASS
- Product frontend broad: 98 PASS; only 2 frozen M1.4 Edit danger-zone baseline failures; M1.5A delta=0
- Typecheck: PASS
- Production Vite build: PASS
- Architecture: M1.5A new violations=0; only frozen-base APP-001/GlobalFeedbackIsland debt remains
- Isolated real-page UAT fixture: 30d 12 units / 3 shipments; 90d 28 / 7; lifetime 66 / 15; sales lifetime 9; derived shipping/sales ratio 7.33x
- Real-page screenshot captured and visually self-verified before Owner Final UAT
- Admin Bypass: OFF
- Commit/push/create PR: OWNER AUTHORIZED after final UAT PASS; merge/deploy/tag/release remain NOT AUTHORIZED
- Owner Final UAT: PASS (2026-09-03); unit labels explicitly show 件 vs 笔订单/笔发货.


- Isolated UAT: http://127.0.0.1:18119, SQLite /tmp/byl-erp-m15-owner-uat.sqlite, real Fortify auth, Admin Bypass OFF.
- Automated/self UAT: 12/12 scenarios PASS; Owner intermediate screenshot/pass not required. Owner Final UAT: PASS (2026-09-03).
- Product360 focused backend: 7 tests / 47 assertions PASS.
- Product frontend focused: Show + Index = 27/27 PASS.
- Product frontend broad: 96 PASS / 2 frozen Edit danger-zone baseline failures; M1.5 delta = 0.
- Full Feature suite: 805 tests / 6620 assertions, 1 RuntimeFoundation baseline failure + 2 skipped; M1.5 runtime-file delta = 0.
- Production Vite build PASS; vue-tsc PASS; git diff --check PASS.
- Architecture raw baseline: 14 APP-001 + pre-existing GlobalFeedbackIsland hash mismatch; exact M1.5 architecture delta = 0.
- 9 Product360 tabs screenshot-swept at 1200x900; no page/console errors and no visual overflow/duplicate-heading regression observed.
- No commit, push, PR, merge, deploy, Production migration, tag/release or worktree cleanup performed.
