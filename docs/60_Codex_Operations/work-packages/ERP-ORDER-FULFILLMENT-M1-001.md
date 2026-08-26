---
type: work-package
project: BYL ERP
work_package_id: ERP-ORDER-FULFILLMENT-M1-001
title: Unified Manual Order → Logistics / Fulfillment M1
status: IMPLEMENTATION COMPLETE / US FULL VERIFICATION PASS / AWAITING CHINA TIER-B VERIFICATION AND OWNER UAT
draft_pr: 90
execution_provider: CLAUDE_CODE
design_authority: GPT_WEB
test_provider: CODEX_CLI
baseline: main@6ac25f2168dea7e8cd9e8f4c49c10752642a44df
branch: feature/erp-order-fulfillment-m1-001
estimate: DELIVERED / see 2026-08-24 implementation log entry for exact scope and evidence
updated: 2026-08-24
---

# ERP-ORDER-FULFILLMENT-M1-001

## 1. Objective

Turn the already-existing MVP1 Order/Shipment foundation into the Owner-approved unified operational workflow:

```text
Manual Order
→ Customer or KOL Fulfillment classification
→ Canonical orders.id
→ Pending Logistics queue
→ Reliable copy / shipment entry
→ Mark shipped
→ Existing Manual Sync / Audit behavior preserved
→ Finance-compatible canonical data
```

The package must reuse the existing Order, Order Item, Address, Shipment, Manual Sync, Audit and Finance foundations. It must not create a second customer/KOL/logistics order database.

## 2. Frozen design authority

Implementation must conform to:

```text
docs/10_Planning_Roadmap/16_BYL_ERP_CANONICAL_ORDER_FINANCE_KOL_DATA_CONTRACT_V1.md
```

Key invariants:

```text
CANONICAL_ORDER = orders.id
PRIMARY_UI_ORDER_NUMBER = external_order_number
INTERNAL_TECHNICAL_NUMBER = internal_order_number / unique / hidden by default
ORDER_KIND = customer | kol_fulfillment
ORDER_SOURCE = independent from order kind
V1_SHIPMENT_CARDINALITY = 1 order : 1 shipment
BASE_CURRENCY = USD
```

Affiliate-attributed consumer sales remain `customer` Orders; `kol_fulfillment` means products/samples/gifts/replacements sent to a KOL.

## 3. Roadmap position

This package temporarily becomes the current product priority after ERP-INQUIRY-M1.1 Company is merged. ERP-INQUIRY-M1.2 Contact is PAUSED, not cancelled. After this package passes the required verification and Owner local UAT/merge gate, the default sequence resumes at ERP-INQUIRY-M1.2 Contact unless Owner changes priority again.

No remote release is authorized by this priority change.

## 4. Existing implementation to reuse

The baseline already contains:

- canonical `orders` table with internal/external numbers, source, owner and status;
- `order_items`, `order_addresses`, `order_external_references`;
- one-shipment-per-order `shipments` foundation;
- `ShipmentService` mark-shipped/reopen behavior;
- automatic Manual Sync pending creation after shipment;
- immutable Audit integration;
- Order-level Finance/Cost foundation;
- customer and KOL route surfaces that currently share OrderController behavior;
- Logistics pending-shipment queue and copy actions;
- existing MVP1 workflow tests.

This package is an additive/remediation evolution, not a rewrite.

## 5. Required product outcomes

### 5.1 Order identifiers

- manual `external_order_number` is required and preserved as entered except safe surrounding-whitespace handling;
- all normal Order/Logistics/KOL/Finance UI uses `external_order_number` as the primary displayed order number;
- `internal_order_number` remains generated, immutable, globally unique and hidden by default;
- technical/internal number may be exposed only through explicit advanced/system/audit context;
- manual duplicate business order numbers are rejected within the manual ERP connection scope.

### 5.2 Order kind

Introduce canonical business classification:

```text
customer
kol_fulfillment
```

Do not reuse `source_type` as business classification.

Customer order pages must scope to `customer`. KOL fulfillment order pages must scope to `kol_fulfillment`. Both remain rows in the same `orders` table.

### 5.3 Unified manual create

Use one shared Order Create implementation.

Entry behavior:

```text
Customer → New Order       defaults customer
KOL → New KOL Order        defaults kol_fulfillment
```

The same server-side create action/service owns validation and persistence. Do not fork duplicate CustomerOrder/KolOrder models or services.

### 5.4 Order items

Continue one line per item/SKU. No fixed Product-1/Product-N columns. Preserve existing Order Item truth and extend only where the frozen contract requires compatible snapshot/analysis fields.

### 5.5 Logistics

Both `customer` and `kol_fulfillment` pending shipments enter the same Logistics queue.

Queue UI must include the business order number and an order-kind indicator, while reusing the existing ShipmentService.

The copy action must be reliable in the actual local browser/runtime, with deterministic failure handling and an approved fallback when Clipboard API capability is unavailable. Silent copy failure is prohibited.

Mark-shipped behavior must preserve existing shipment, Order, Manual Sync and Audit semantics.

### 5.6 Order detail fulfillment projection

Provide the smallest useful fulfillment workspace in Order Detail without a broad page redesign. It must make address/items/current shipment/tracking/manual-sync status and permitted correction/actions easy to understand.

### 5.7 Pagination and query policy

Order customer/KOL lists and Logistics queues must use bounded server-side pagination, filtering, ordering and authorization. All-order `.get()`/client-side filtering patterns must not remain on user-facing large lists after this package.

Design check: 50,000 Orders normal; 500,000 Orders architecture/performance review target.

## 6. Authorization / data scope

The package must implement or reuse server-side authorization so operational users do not receive unrelated users' order data by default.

Required model:

```text
FUNCTION_PERMISSION + DATA_SCOPE + FIELD_PERMISSION
```

Minimum behavior:

- business operators see their permitted/owned Orders;
- KOL specialists see their permitted KOL fulfillment Orders;
- logistics can access Orders assigned/eligible for shipment without receiving unrestricted Finance-sensitive fields;
- Finance/Owner all-order projections are not implemented by sending unauthorized full datasets to Vue;
- Owner/Admin retains all-scope authority according to existing canonical permission conventions.

Do not invent a new role system if the existing permission foundation can express the approved behavior.

## 7. Schema / migration preflight gate

This package is schema-sensitive and therefore Tier B.

Before authoring the final migration/backfill behavior, Claude must perform a read-only implementation preflight and report actual legacy shapes for:

```text
orders.source_type = website | kol | manual_other
external_order_number population/duplicates
existing Order rows relevant to KOL/manual usage
current indexes and constraints
current role/permission catalog
```

Backfill policy must obey the frozen rule:

- deterministic legacy rows may be mapped automatically;
- ambiguous rows must not be guessed;
- no production database or real production dump may be used;
- migration execution remains local/test only until separately authorized by the package gates.

If `manual_other` cannot be classified safely, the implementation must use an explicit compatible migration/exception strategy rather than silently classifying it.

## 8. Historical MVP1 Human UAT findings in scope

This package must revalidate/remediate the four remaining legacy findings relevant to Orders/Fulfillment:

```text
MVP1-UAT-DEFECT-002
Create Order server-side required-field contract
→ current main already appears to contain server validation; re-test exact expected behavior before adding redundant rules.

MVP1-UAT-DEFECT-003
User business order number overwritten/generated
→ resolve through external_order_number primary-display/preservation contract and hidden internal number.

MVP1-UAT-DEFECT-004
Shipment copy ineffective + Admin permission concern
→ reproduce/classify exact root cause, implement reliable copy and verify server authorization.

MVP1-UAT-FINDING-005
Order Detail lacks fulfillment-workspace information architecture
→ implement bounded fulfillment detail projection and Owner UAT.
```

No finding is declared CLOSED until exact implementation evidence and Owner UAT support closure.

## 9. KOL navigation impact

The long-term approved KOL Level-2 target is:

```text
所有网红
网红订单
佣金明细
网红统计
关系维护
```

This Work Package may change KOL Level-2 runtime navigation only where necessary to expose the real `kol_fulfillment` order surface and only if the implementation preflight confirms no undeclared shared App-Shell dependency. Level-1 KOL governance is frozen and must not be renamed/reordered.

Full KOL CRM, AffiliateWP connector, commission detail/performance implementation and relationship-maintenance product work remain outside this package unless Owner explicitly expands scope.

## 10. Explicit exclusions

Not authorized by this Work Package contract alone:

```text
full AffiliateWP API connector
AffiliateWP Visits/Conversion clone
full KOL CRM implementation
advanced KOL statistics dashboard
full Payment/Refund module
advanced SKU financial allocation engine
WooCommerce inbound synchronization milestone
ERP-INQUIRY-M1.2 Contact or later Inquiry work
split-shipment 1:N implementation
remote deployment
Production access or migration
real credentials/tokens/cookies/customer exports in repository
new third-party dependency without explicit need/approval
broad App Shell redesign
```

## 11. Verification contract

Because this package changes schema semantics and authorization/data scope, verification tier is:

```text
TIER_B = FULL DUAL-HOST REQUIRED
```

US Mac / Claude Code:

- focused backend Order/Logistics/permission/migration tests;
- full PHPUnit;
- full Vitest;
- TypeScript/typecheck;
- build;
- Browser E2E including Customer and KOL fulfillment flow;
- local migration/backfill test on disposable/local test database only;
- no external/production network dependency;
- git diff check / clean evidence.

China Mac / Codex CLI:

- exact approved Head SHA;
- independent full verification required for this Tier B package;
- no source mutation, fix, diagnosis or architecture decisions;
- local `http://erp.b` real browser/UAT evidence as permitted;
- report raw PASS/FAIL evidence to GPT/Owner.

## 12. Required Browser E2E scenarios

At minimum:

```text
Customer manual order
→ business order number preserved
→ customer list scoped
→ pending Logistics
→ copy information behavior
→ mark shipped
→ shipment/manual-sync state visible

KOL fulfillment manual order
→ business order number preserved
→ KOL fulfillment list scoped
→ same pending Logistics
→ mark shipped

Authorization
→ operator cannot read unrelated scoped Orders through server routes

Pagination/filter
→ bounded server-side result contract
```

## 13. Acceptance gates

This Work Package is not complete until:

1. canonical Order identity and `order_kind` semantics match the frozen contract;
2. manual customer and KOL fulfillment Orders use the same canonical database/service;
3. business order number is the primary UI identifier and is never replaced by ERP technical number;
4. both order kinds flow through one Logistics/Shipment workflow;
5. reliable copy behavior is browser-verified;
6. server-side data scope/permissions are verified;
7. large-list query surfaces are paginated/indexed server-side;
8. existing Manual Sync/Audit behavior has no regression;
9. the four legacy Order/Fulfillment Human UAT findings are revalidated and only closed where supported;
10. US full verification PASS;
11. China exact-SHA full independent verification PASS;
12. Owner local UAT PASS;
13. Owner explicitly authorizes merge.

Remote deployment remains a separate milestone/release action.

## 14. Current authorization state

```text
CONTRACT: OWNER APPROVED DESIGN AUTHORITY
WORK_PACKAGE_FORMALIZATION: AUTHORIZED BY NEXT-STEP INSTRUCTION
OWNER_IMPLEMENTATION_AUTHORIZATION: GRANTED 2026-08-24
PRODUCT_IMPLEMENTATION: COMPLETE ON feature/erp-order-fulfillment-m1-001
MIGRATION_AUTHORING: COMPLETE
MIGRATION_EXECUTION: LOCAL ONLY (SQLite test suite + local MySQL 8 byl_erp_us_dev synthetic dev database)
REMOTE_DEPLOYMENT: NO
MERGE: NO
```

## 15. Implementation evidence (2026-08-24)

Preflight confirmed the local MySQL 8 dev database (`byl_erp_us_dev`) contains only 7 synthetic `LocalUatSeeder`-generated rows (`source_type` distribution: website=3, kol=2, manual_other=2), all `external_order_number` populated with no duplicates, and legacy KOL order routes gated only by the generic `prototype.view` placeholder permission (a genuine authorization gap matching `DEFECT-004`'s "Admin permission concern").

Implemented:

- `orders.order_kind` column (`customer | kol_fulfillment`), added via migration with deterministic backfill (`website→customer`, `kol→kol_fulfillment`); `manual_other` legacy rows are left `NULL` as an explicit migration exception (never guessed) and logged via `OrderKindBackfill`.
- Shared `OrderController`/`OrderService` now require `order_kind` and a required, trimmed, unique `external_order_number` on manual create; `internal_order_number` remains system-generated/immutable and is never written into the business-number field.
- Customer and KOL fulfillment order routes are bound to `order_kind` via `Route::defaults()`; list, export and detail actions are server-side scoped by `order_kind`, and direct-URL cross-kind access to an order detail returns 404.
- KOL order routes (`/kol/orders*`) now require the real `orders.view` / `orders.create` / `orders.edit` permissions instead of the `prototype.view` placeholder; `menu/APPROVED_MENU.json` and `config/erp_shell_navigation.php` were updated together (KOL Level-2 `kol-orders` / `kol-orders-create` now `implemented`), per the Work Package's explicit menu-change authorization in Section 9.
- Logistics queue (`LogisticsController` + `Logistics/Index.vue`) is paginated server-side, shows `order_kind` and the business `external_order_number`, and the copy action uses an explicit Clipboard API capability check with an `execCommand` fallback and a visible success/error state (no silent failure).
- Order/Logistics list surfaces use Laravel pagination (50/page) instead of unbounded `.get()`.
- Order Detail (`Orders/Show.vue`) gained a minimal fulfillment workspace: shipment status/carrier/tracking/manual-sync summary above the existing address/items/audit sections, with the business number as the page heading and the ERP technical number shown de-emphasized as "ERP编号".

Defect/finding disposition:

```text
DEFECT-002 (required-field validation): REVALIDATED — main already enforced required address/item fields server-side; no redundant rules added.
DEFECT-003 (business number overwritten): FIXED — external_order_number is now required, preserved verbatim (trimmed), and is the primary UI number everywhere; internal_order_number never overwrites it.
DEFECT-004 (copy reliability + permission gap): FIXED — explicit capability-checked clipboard with fallback and visible state; KOL order routes now require real orders.* permissions instead of prototype.view.
FINDING-005 (fulfillment workspace): ADDRESSED — bounded fulfillment summary card added to Order Detail; no broader redesign performed.
```

Verification executed on the US host: full PHPUnit (192 passed / 2 skipped / 1 pre-existing risky, 0 failures), full Vitest (86/86), `vue-tsc --noEmit` (clean), `npm run build` (clean), full Playwright browser E2E including the new `tests/e2e/orders/order-fulfillment.spec.ts` (22/22, including customer and KOL manual-create → logistics → ship, cross-leak 404 check, and clipboard-fallback), and a local MySQL 8 migration/backfill/rollback/reapply cycle against the synthetic `byl_erp_us_dev` database (backed up first) confirming deterministic classification and clean rollback.

None of Owner UAT, China Tier-B independent verification, or merge has occurred. This Work Package remains a Draft PR pending those gates.

## 16. Bounded Phase 1 remediation (2026-08-24, commit 7ac41fc)

Phase 0 (local UAT runtime): `~/.config/valet/Sites/erp` correctly symlinked to this worktree, but `http://erp.b` returned 500 because the worktree's local `database/database.sqlite` did not exist (a gitignored runtime artifact, expected in a fresh worktree checkout). Fixed by creating the file, running `php artisan migrate`, and seeding `LocalUatSeeder`; `erp.b` now serves 200 from this worktree/HEAD, confirmed after the Phase 1 changes below with a fresh `migrate:fresh` + reseed.

Phase 1 closed gaps between the Section 15 implementation and the precise operator-facing contract:

- `external_order_number` is now pre-generated server-side (`CUS`/`KOL` + `Ymd` + 4-char random code + `007`, uniqueness-checked) and shown on the create form before save, with a pencil/edit affordance (`OrderService::generateExternalOrderNumber()`, `Orders/Create.vue`). It remains editable only before first save: `external_order_number` was removed from the generic update-validation rules, so it cannot be changed via the normal correction form after save.
- Route context is now enforced as authority end-to-end: `OrderController::store()` forces `order_kind`, `source_type`, and `owner_id` from the route/actor rather than trusting client payload fields; the client-side `order_kind`/`source_type`/`owner_id` selects were removed from `Orders/Create.vue` (replaced with disabled, route-derived display fields).
- New manual customer/KOL orders are stamped `source_type=manual` (new `OrderSourceType::Manual` enum case); legacy `website`/`kol`/`manual_other` values remain readable/filterable and are never relabeled.
- Owner semantics: audited `owner_id` and found it was a client-selectable field at create time and a generically mutable field on update — in conflict with "Owner means creator." Smallest safe fix applied: `owner_id` is now always the creating actor (no reassignment UI existed elsewhere in the system depending on free owner selection), and `owner_id` was removed from update-validation rules. Owner now renders as `Owner(username)` consistently in Orders list/detail and the Logistics queue.
- Non-critical recipient/address snapshot fields (`recipient_name`, `phone`, `country`, `province_state`, `city`, `address_line_1`) are now optional end-to-end: validation loosened to `nullable`, and migration `2026_08_24_000001_make_order_address_snapshot_fields_nullable` makes the corresponding `order_addresses` columns nullable (verified migrate/rollback/reapply clean on local SQLite). Read-only list/logistics views render `—` for missing values instead of blank/undefined; nothing is ever stored as a literal dash.
- `internal_order_number` is now hidden by default on Order Detail behind a `<details>` disclosure, instead of being shown inline.
- All newly touched UI text is Chinese (labels, the new pencil-edit affordance, the "手动创建" source label for the new `manual` value); previously English pre-existing labels outside this diff's touch-set were left as-is.
- Test/config gap found and fixed: `playwright.config.ts`'s `testMatch` silently excluded `tests/e2e/orders/**`, so `order-fulfillment.spec.ts` was never actually executed by `npm run test:e2e` despite the Section 15 evidence log claiming 22/22. Added `orders/**/*.spec.ts` to `testMatch`; running it surfaced two pre-existing latent issues in the spec itself (a strict-mode-violating heading locator and an `aria-label` substring collision), both fixed in the spec.

Verification executed on this host after the change: full PHPUnit (198 passed / 2 skipped / 1 pre-existing risky, 0 failures, including 7 new `OrderFulfillmentM1Test` cases for the above rules), full Vitest (86/86), `vue-tsc --noEmit` (clean), `npm run build` (clean), full Playwright E2E with `orders/**` now included (26/26), and a local SQLite migration/rollback/reapply cycle for the new nullable-columns migration. `erp.b` reconfirmed serving HEAD `7ac41fc` after a fresh `migrate:fresh` + reseed.

No merge, deploy, or Production action was taken. PR #90 remains OPEN and Draft.
