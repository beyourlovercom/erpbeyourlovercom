---
type: byl-erp-work-package
project: BYL ERP
work_package: ERP-ORDER-PRODUCT-SELECTOR-FEEDBACK-UAT-001
status: implementation-authorized
standard_id: LARGE-PROJECT-EXEC-001
standard_version: 1.3
---

# ERP-ORDER-PRODUCT-SELECTOR-FEEDBACK-UAT-001

## Authority

```text
OWNER_AUTHORIZATION: EXPLICIT / 2026-09-02
AUTHORIZED_BASE: 09d316fa5add5c243cfbb7edb7b4275ea6c83a8a
BRANCH: feature/erp-order-product-selector-feedback-uat-001
WORKTREE: /Users/a/AI/007/worktrees/erp-order-product-selector-feedback-uat-001
EXECUTION_PROVIDER: GPT_WEB
EXECUTION_SURFACE: US_MAC / REMOTE_DESKTOP_COMMANDER
OWNER_PROVIDER_CONFIRMATION: EXPLICIT / 2026-09-02
GLOBAL_PROVIDER_GOVERNANCE_CHANGE: NO
CLAUDE_USAGE: FORBIDDEN FOR THIS WP UNLESS RE-AUTHORIZED
CODE_CHANGE: YES / BOUNDED
MERGE: NO
DEPLOYMENT: NO
PRODUCTION_MUTATION: NO
```

Owner explicitly confirmed GPT Web + Desktop Commander for this bounded UAT remediation. This matches `AI/EXECUTION_ROLE_MODEL.md` v1.3, which authorizes GPT Web for narrow, well-understood remediation with frozen behavior. No global provider-governance mutation is required.
## Scope

```text
A. Upgrade Order Create Product/SKU selector to a searchable dropdown.
B. Empty-query focus/click opens valid Products sorted SKU ASC.
C. Search priority: SKU prefix → SKU contains → Product name contains.
D. Selection binds canonical product_id; subsequent typing clears product_id.
E. Order Create validation publishes concise human-readable Global Feedback.
F. Preserve field-level validation and existing success flash behavior.
```

Allowed implementation surface:

```text
resources/js/Components/Products/SkuCombobox.vue
resources/js/Components/Products/SkuCombobox.test.ts
resources/js/Pages/Orders/Create.vue
resources/js/Pages/Orders/Create.test.ts
resources/js/Components/AppShell/GlobalFeedbackIsland.vue
resources/js/Components/AppShell/GlobalFeedbackIsland.test.ts
app/Modules/Products/Experience/Controllers/ProductController.php
app/Modules/Products/Infrastructure/EloquentProductRepository.php
focused Product search tests
this Work Package record
```

No Order Identity changes, historical backfill, Product Master redesign, Woo/Inventory redesign, migration-ledger cleanup, Production deployment, tag/release or unrelated refactor.
## Acceptance / Verification

```text
EMPTY_FOCUS_DROPDOWN: REQUIRED
DEFAULT_ORDER: SKU ASC, stable secondary name/id
SEARCHABLE: REQUIRED
KEYBOARD_NAVIGATION: REQUIRED
CANONICAL_PRODUCT_ID: REQUIRED
MISSING_PRODUCT_FIELD_ERROR: REQUIRED
GLOBAL_FEEDBACK_TITLE: 订单无法创建
GLOBAL_FEEDBACK_MESSAGE: 请先选择订单商品
GLOBAL_FEEDBACK_NEXT: 选择 SKU / 产品后重新提交
RAW_VALIDATION_KEY_LEAK: FORBIDDEN
SUCCESS_FLASH_DUPLICATION: FORBIDDEN
```

Verification before Owner UAT:

```text
npm run typecheck
focused Vitest: GlobalFeedbackIsland.test.ts + SkuCombobox.test.ts + Orders/Create.test.ts
focused Product backend tests
npm run build
git diff --check
local erp.b Customer + KOL Order Create UAT
```

The retained local UAT database must not be reset or fresh-migrated. `erp.b` may be repointed only after the isolated worktree has valid runtime dependencies and configuration.

## Verification Evidence — 2026-09-02

```text
SHARED_MYSQL_UAT_MUTATION: NONE
SHARED_MYSQL_NOTE: retained schemas predate Product Master and contain no products table
ISOLATED_UAT_DB: worktree-local SQLite / git-ignored
ISOLATED_UAT_DATA: LocalUatSeeder + 3 synthetic Product fixtures
ERP_B_TARGET: /Users/a/AI/007/worktrees/erp-order-product-selector-feedback-uat-001
TYPECHECK: PASS
FOCUSED_VITEST: PASS / 35 tests
FOCUSED_PRODUCT_BACKEND: PASS / 45 tests / 149 assertions
BUILD: PASS
GIT_DIFF_CHECK: PASS
CUSTOMER_CREATE_BROWSER_UAT: PASS
KOL_CREATE_BROWSER_UAT: PASS
DEFAULT_SKU_ASC: PASS / A100,B200,SKU-X9
SEARCH_PRIORITY: PASS
CANONICAL_SELECTION_UI: PASS
GLOBAL_FEEDBACK_BLOCKED: PASS
GLOBAL_FEEDBACK_NEXT_VISIBLE: PASS
RAW_VALIDATION_KEY_LEAK: NONE OBSERVED
OWNER_MANUAL_UAT: PASS / 2026-09-02
```
## Project Position / Estimate

```text
PROGRAM_ROADMAP: MVP1 Existing Operations / Tencent Docs Replacement → legacy Human UAT remediation → later approved milestones
CURRENT_TRACK_PHASE_MAP: bounded legacy Order Create Human UAT remediation
CURRENT_WORK_PACKAGE: ERP-ORDER-PRODUCT-SELECTOR-FEEDBACK-UAT-001
PACKAGE_STATUS: OWNER_UAT_PASS
BASELINE: 09d316fa5add5c243cfbb7edb7b4275ea6c83a8a
DEPENDENCY_TYPE: MERGED_PR
DEPENDENCY: PR #151 Order Identity + PR #105 Global Feedback Island / already merged in baseline
PARALLEL_SAFE: YES / active open PR target-file overlap audit = none
PRODUCTION_DEPLOYMENT_STATUS: NO
```

## ESTIMATED EFFECTIVE HOURS

```text
PREFLIGHT_AND_SCOPE: 0.3–0.6h; planning value 0.4h
CORE_EXECUTION: 0.8–1.5h; planning value 1.1h
TEST_AND_VALIDATION: 0.6–1.2h; planning value 0.8h
REMEDIATION_BUFFER: 0.3–0.8h; planning value 0.5h
ESTIMATED_EFFECTIVE_HOURS: 2.8h
ESTIMATE_RANGE: 2.0–4.1 effective hours
OWNER_REVIEW: INCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
UAT: INCLUDED / local only
DEPLOYMENT: EXCLUDED
```
