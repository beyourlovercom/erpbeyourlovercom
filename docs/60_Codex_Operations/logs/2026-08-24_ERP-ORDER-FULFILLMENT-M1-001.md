---
type: work-log
project: BYL ERP
work_package_id: ERP-ORDER-FULFILLMENT-M1-001
date: 2026-08-24
status: IMPLEMENTATION COMPLETE / US FULL VERIFICATION PASS
---

# Work Log — ERP-ORDER-FULFILLMENT-M1-001

## 2026-08-24 — Owner design decisions frozen / Draft delivery preparation

```text
BASELINE_MAIN: 6ac25f2168dea7e8cd9e8f4c49c10752642a44df
BRANCH: feature/erp-order-fulfillment-m1-001
DESIGN_AUTHORITY: GPT_WEB + OWNER
IMPLEMENTATION_PROVIDER: US_MAC_CLAUDE_CODE
TEST_PROVIDER: CHINA_MAC_CODEX_CLI
GITHUB: UNIQUE_SOURCE_OF_TRUTH
PRODUCT_IMPLEMENTATION: NOT_STARTED / NOT_AUTHORIZED
MIGRATION_EXECUTION: NO
REMOTE_DEPLOYMENT: NO
MERGE_AUTHORIZATION: NO
```

Owner approved the canonical Order / Finance / KOL business semantics, including:

```text
CANONICAL_ORDER_TRUTH = orders.id
PRIMARY_UI_ORDER_NUMBER = external_order_number
INTERNAL_ORDER_NUMBER = generated / unique / immutable / hidden by default
ORDER_KIND = customer | kol_fulfillment
AFFILIATE_ATTRIBUTED_CONSUMER_ORDER = customer + attribution
BASE_CURRENCY = USD
AFFILIATEWP_PAID = actually paid for V1
V1_SHIPMENT = 1 order : 1 shipment
DEFAULT_SHARED_ITEM_COST_ALLOCATION = sales-value proportion unless real item/weight data is available
```

Real Owner-provided WooCommerce and AffiliateWP exports were reviewed outside the repository for design validation. No customer export, AffiliateWP export, credential, token, cookie or production data is committed to the repository.

The current product priority is revised to Unified Manual Order → Logistics / Fulfillment. ERP-INQUIRY-M1.1 Company is already merged; ERP-INQUIRY-M1.2 Contact is PAUSED, not cancelled, and resumes after this package unless Owner changes priority again.

Historical Order/Fulfillment Human UAT findings targeted for exact revalidation are `MVP1-UAT-DEFECT-002`, `MVP1-UAT-DEFECT-003`, `MVP1-UAT-DEFECT-004` and `MVP1-UAT-FINDING-005`. None is closed by documentation alone.

Estimate remains `UNKNOWN WITH EVIDENCE` until the implementation preflight confirms exact migration/backfill behavior, legacy `source_type` data shape, current permission/data-scope integration and affected runtime surfaces. No estimate is fabricated from chat elapsed time.

## 2026-08-24 — Draft delivery PR opened

```text
PR: #90
PR_STATE: OPEN / DRAFT
INITIAL_PR_HEAD: 480ae77bfddeb2b79ff04ef58a1c655e91fe7e69
BASE: main@6ac25f2168dea7e8cd9e8f4c49c10752642a44df
DIFF_CLASSIFICATION: DOCUMENTATION / GOVERNANCE ONLY
PRODUCT_IMPLEMENTATION: NOT AUTHORIZED
MIGRATION_AUTHORING: NOT AUTHORIZED
MIGRATION_EXECUTION: NO
REMOTE_DEPLOYMENT: NO
MERGE: NO
NEXT_GATE: EXPLICIT OWNER IMPLEMENTATION AUTHORIZATION
```

## 2026-08-24 — Owner implementation authorization granted / US Claude implementation

```text
OWNER_IMPLEMENTATION_AUTHORIZATION: GRANTED
IMPLEMENTATION_PREFLIGHT: PASS
START_HEAD: 8b82820065c5c34a80855c6a01a4a2faa02c2aa9
```

Preflight (read-only) findings:

- Local MySQL 8 dev database `byl_erp_us_dev` contained exactly 7 orders, all produced by `database/seeders/LocalUatSeeder.php` (synthetic, fully reconstructable): `source_type` distribution website=3, kol=2, manual_other=2. All `external_order_number` populated, zero duplicates.
- `/kol/orders*` routes were gated only by the generic `prototype.view` placeholder permission, with the KOL "订单" and "新建订单" Level-2 menu items marked `planned/0%` in the Owner-approved menu baseline despite the routes being live — a genuine authorization gap consistent with `MVP1-UAT-DEFECT-004`'s "Admin permission concern" language.
- `orders.internal_order_number` (system-generated) was the only order number surfaced in the UI everywhere; `external_order_number` existed but was optional and never displayed — the exact `MVP1-UAT-DEFECT-003` defect.
- Logistics copy action called `navigator.clipboard.writeText` with no capability check, try/catch, or fallback — the exact `MVP1-UAT-DEFECT-004` copy-reliability defect.
- `manual_other` legacy rows cannot be deterministically mapped to `order_kind`; per the Work Package's own anticipated exception strategy (Section 7), these are backfilled to `order_kind = NULL` rather than guessed, with a logged migration exception (`OrderKindBackfill`).
- No unresolved contract conflict was found. `IMPLEMENTATION_PREFLIGHT=PASS`.

Implementation performed on branch `feature/erp-order-fulfillment-m1-001` (see the Work Package document Section 15 for the full implementation evidence summary): `orders.order_kind` schema/migration/backfill, shared Order create/list/detail scoping by `order_kind`, business-order-number preservation, KOL route permission correction (`prototype.view` → `orders.*`), paginated Order/Logistics lists, reliable Logistics copy with fallback, and a bounded Order Detail fulfillment workspace.

US full verification executed and passed: full PHPUnit (192 passed, 2 skipped, 1 pre-existing risky, 0 failures), full Vitest (86/86), `vue-tsc --noEmit` clean, `npm run build` clean, full Playwright browser E2E (22/22, including new `tests/e2e/orders/order-fulfillment.spec.ts`), and a local MySQL 8 migrate/backfill/rollback/reapply cycle against a backed-up copy of `byl_erp_us_dev`. `git diff --check` clean; worktree clean after commit.

```text
MERGE_AUTHORIZATION: NO
DEPLOYMENT: NO
NEXT_GATE: CHINA CODEX TIER-B INDEPENDENT VERIFICATION, THEN OWNER LOCAL UAT
```
