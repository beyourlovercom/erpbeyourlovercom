---
type: work-package
project: BYL ERP
work_package_id: ERP-CUSTOMER-M1-2-SEGMENTATION-001
status: M1_2_MERGED / GLOBAL_DATA_GRID_V1_1_OWNER_UAT_PASS / READY_FOR_GIT_DELIVERY
execution_provider: GPT_WEB_OWNER_AUTHORIZED
baseline: origin/main@9052d1ed8c1b361264c4b7802d99fba2eaba6611
---

# ERP Customer M1.2 — Segmentation Foundation

## Goal
Add the first four system-computed Customer segments to the canonical Customer List without creating a second Customer truth or copying Order facts into Customer records.

## Canonical segment contract
Only explicit `orders.customer_id` relations count. A valid purchase order is `order_kind=customer` and `order_status != cancelled`.

- `no_purchase`: valid order count = 0.
- `new_customer`: valid order count = 1.
- `repeat_customer`: valid order count >= 2.
- `multi_product_single_order`: at least one valid order has `COUNT(DISTINCT non-empty order_items.sku) >= 3`.

Quantity does not substitute for distinct SKU count. Distinct SKUs spread across different orders do not satisfy the single-order multi-product segment.

## Final Owner-approved UX contract
- Canonical Customer workspace: `/customers/library`.
- Header shortcuts: `全部客户 / 无购买客户 / 新客户 / 复购客户 / 单次多产品客户`.
- Shortcuts stay on one header row; the shortcut rail may scroll horizontally when space is insufficient.
- `新建客户` remains the dominant rightmost action on the same row.
- `/customers/groups` is compatibility-only and redirects to `/customers/library` while preserving query parameters.
- The shell exposes `客户列表`; there is no static `客户分组` secondary page in M1.2.
- Manual Groups remain deferred until groups become managed business objects.

## Customer Data Grid
The canonical Customer List owns the configurable grid.

Default real-data columns:
- Customer Number + Name (fixed identity)
- Email
- Phone
- Registration date (`customers.created_at`)
- Country / region
- City
- State / province
- Postal code
- Source
- Order count
- Lifetime sales by currency
- AOV by currency
- Latest order date
- Owner
- Status

Address projection uses Customer Master shipping address first and billing address only as fallback. Order-derived facts continue to require explicit `orders.customer_id`. Different currencies are never summed into one canonical amount.

The table `…` control supports show/hide, vertical drag reorder and reset. Preferences persist in browser localStorage under `customer_list_grid_columns_v1`; `customer_groups_grid_columns_v1` is accepted only as a legacy migration fallback. Identity and row action remain fixed. Horizontal table scrolling is allowed.

`Username` and website-level `Last active` are excluded because no canonical Woo Customer/session authority exists. They must not be inferred from email, ERP activity or order dates.

## Explicit exclusions
- No Customer business-schema expansion.
- No `user_ui_preferences` table, account-sync preference API or cross-device Grid authority in M1.2.
- No manual customer groups.
- No high-value, high-frequency, active, dormant, churn-risk or VIP segments.
- No Subscriber/Support segments.
- No fuzzy Customer ↔ Order matching or historical backfill.
- No changes to Order truth, Finance truth or Customer import/export engines.
- No KOL implementation or KOL Architecture baseline repair.

## Preserved M1.1 contracts
- Customer KPI strip and ECB RMB display remain unchanged.
- Filter module remains one horizontal row with horizontal overflow rather than wrapping.
- Customer Import / Export remain Administrator-only.
- Customer-order CSV export remains Administrator-only.
- Customer360 identity and address truth remain unchanged.

## Owner UAT
OWNER_UAT: PASS
OWNER_UAT_DATE: 2026-08-29
OWNER_DELIVERY_AUTHORIZATION: commit + push + Draft PR authorized on 2026-08-29
MERGE: NOT AUTHORIZED / separate Owner gate
PRODUCTION_DEPLOYMENT_STATUS: NOT AUTHORIZED

Owner accepted the final fusion layout at `http://127.0.0.1:18114/customers/library`, including the five segment shortcuts, single-row header, configurable Data Grid and compatibility redirect.

## Final pre-commit verification
- Baseline: `origin/main@9052d1ed8c1b361264c4b7802d99fba2eaba6611`.
- Branch divergence before delivery: `0 / 0` against origin/main.
- Customer/M1.1/Menu/PageHeader focused regression: 68 tests / 1860 assertions PASS.
- Customer List Vitest: 13/13 PASS.
- `npm run typecheck`: PASS.
- Fresh production build: PASS.
- Runtime bundle: `assets/app-CSgjjQNd.js`.
- `git diff --check`: PASS.
- `public/hot`: absent.
- Real Chrome runtime `/customers/library?group=repeat_customer`: HTTP 200.
- All five segment shortcuts: visible.
- Active shortcut: `复购客户`.
- Shortcut rail and `新建客户`: same row; `新建客户` remains to the right.
- Column settings trigger: visible.
- Static `客户分组` left-menu entry: absent; `客户列表`: present.
- Duplicate Customer Group select: absent.
- `/customers/groups?group=no_purchase&country_code=US` resolves to canonical Customer List with both query parameters preserved.
- ModuleArchitecture: 3 failed / 5 passed, matching the known non-Customer KOL Content/AffiliateWP baseline drift; no Customer/Menu/FE failure is introduced.
- No `user_ui_preferences`, UserUiPreference service/controller/model, account-sync composable or preference migration remains in the package.

`CUSTOMER_M1_2_SEGMENTATION_FOUNDATION = OWNER_UAT_PASS / DELIVERY_READY`

PROJECT POSITION
PROGRAM_ROADMAP: Customer M1.1 Experience → Customer M1.2 Segmentation Foundation → Customer M1.3 Subscriber Integration → Customer M1.4 Support Integration → Customer M1.5 Fulfillment Projection → Customer M1.6 Advanced Segmentation → Customer M1.7 Manual Groups → Customer M1.8 Full Import/Export → Customer M1.9 Customer360 Closure
CURRENT_PHASE: Customer M1.2 GitHub delivery
CURRENT_WORK_PACKAGE: ERP-CUSTOMER-M1-2-SEGMENTATION-001
PACKAGE_STATUS: OWNER_UAT_PASS / DELIVERY_READY / DRAFT_PR_120_OPEN
WAITING_PHASES: Draft PR #120 exact-head gate; separate Owner merge authorization; Customer M1.3
BASELINE: origin/main@9052d1ed8c1b361264c4b7802d99fba2eaba6611
SOURCE_CONFLICTS: NONE at pre-commit gate
GLOBAL_GATE: PACKAGE REGRESSION PASS; repository-global ModuleArchitecture retains 3 pre-existing KOL baseline failures
GITHUB_STATE: Draft PR #120 open; head branch feature/erp-customer-m1-2-segmentation-001; base main
NEXT_DEPENDENCY: exact-head verification of Draft PR #120 → separate Owner merge gate
EVIDENCE_AS_OF: 2026-08-29

OWNER_REVIEW: INCLUDED / PASS
MERGE: EXCLUDED / SEPARATE OWNER GATE
MIGRATION_FILE_AUTHORING: NONE
MIGRATION_EXECUTION: NONE FOR M1.2
UAT: INCLUDED / OWNER PASS
DEPLOYMENT: EXCLUDED

## Post-merge superseding amendment — Global Data Grid VI V1.1 Account Sync

PR #120 merged the earlier M1.2 Customer List fusion into `main`. The Owner subsequently superseded only the Grid/navigation preference portion of that layout; the four M1.2 segment definitions remain unchanged.

Current controlling decisions:
- `/customers/library` remains the standard Customer List workspace and keeps the Customer Group select.
- `/customers/groups` is again a real governed level-2 workspace and the Global Data Grid VI V1.1 reference implementation.
- Customer Groups header exposes `全部客户 / 无购买客户 / 新客户 / 复购客户 / 单次多产品客户` immediately left of `新建客户`.
- The configurable `…` Column Settings UI is scoped to the Customer Groups reference workspace.
- `user_ui_preferences` is the single generic account UI-preference table with unique `(user_id, namespace)` authority.
- `grid.customers.groups.v1` is the current reference namespace.
- Server preference is canonical across devices; browser localStorage is cache / legacy migration fallback only.
- Stored preferences contain column keys, visibility and order only; business/query/permission definitions remain code-owned.
- `useAccountGridPreferences` is the reusable shared frontend primitive for future grids.
- Preference sanitization removes unknown/duplicate columns and cannot bypass current permissions.

GLOBAL_STANDARD: `docs/10_Planning_Roadmap/26_BYL_ERP_GLOBAL_DATA_GRID_VI_V1_1.md`.
APPROVED_MENU_VERSION_TARGET: 1.7.
DATABASE_EXPANSION: one generic `user_ui_preferences` table only.

## Global Data Grid VI V1.1 final closure — 2026-08-29

OWNER_UAT: PASS.
EXACT_V1_1_BASE: `origin/main@436285faa6c35f0b93ab2c0256382d48db48472c` after PR #120 merged M1.2.

Final gate evidence on that exact base:
- Focused PHP: 71 passed / 1907 assertions.
- Customer Grid Vitest: 15/15 PASS.
- Typecheck PASS.
- Fresh production build PASS.
- `git diff --check` PASS; `public/hot` absent.
- Production/runtime bundle: `assets/app-DFmh1Bqv.js`.
- Runtime `/customers/groups`: HTTP 200, component `Customers/Library/Index`, workspace `groups`.
- Runtime preference namespace: `grid.customers.groups.v1` with server preference present.
- Same-account cross-session smoke: Session A save HTTP 200; fresh Session B value SHA256 matched exactly.
- Preference migration rehearsal: up → unique-key rejection → rollback → reapply PASS.
- ModuleArchitecture on candidate: 3 failed / 5 passed.
- Untouched exact `main@436285f`: the same 3 failed / 5 passed; all failures are pre-existing KOL baseline drift.

GLOBAL_DATA_GRID_VI_V1_1: OWNER_UAT_PASS / READY_FOR_GIT_DELIVERY.
GIT_SCOPE: V1.1 account-sync follow-up only; M1.2 base functionality is already in main via PR #120.
COMMIT_PUSH_PR: OWNER AUTHORIZATION REQUIRED.
MERGE: SEPARATE OWNER GATE.
