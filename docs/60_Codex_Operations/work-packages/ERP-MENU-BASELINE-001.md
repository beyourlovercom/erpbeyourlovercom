---
type: byl-erp-work-package
project: BYL ERP
work_package: ERP-MENU-BASELINE-001
status: merged-pass
standard_id: LARGE-PROJECT-EXEC-001
standard_version: 1.1
---

# ERP-MENU-BASELINE-001 — Approved Menu Baseline

## Current Accepted Status

```text
STATUS: MERGED / PASS
PR: #14
ACCEPTED_HEAD: 6d5595e879778895105215dc98b1f740521b6f1a
MAIN_SQUASH_COMMIT: 493f32ce7bf1be4368a7e9f97de9ecf9f9c9c9d8
MERGED_AT: 2026-08-03T05:50:32Z
MENU_BASELINE_VERSION: 1.0
MIGRATION: NO
DATABASE_MUTATION: NO
UAT: NO
DEPLOYMENT: NO
```

The original implementation and slug-remediation record below is retained unchanged as historical evidence.

## Work Package

```text
ID: ERP-MENU-BASELINE-001
TITLE: Approved Menu Baseline
STATUS: SLUG GOVERNANCE REMEDIATION COMPLETE / PENDING INDEPENDENT REVIEW
AUTHORIZED_BASE: 8fe6ba786af1d794b63819ea94aaa45cecd1e1e4
SOURCE_BRANCH: feature/erp-shell-account-dock-001
IMPLEMENTATION_BRANCH: feature/erp-menu-baseline-001
AUTHORIZATION_TYPE: BOUNDED GOVERNANCE AND BASELINE IMPLEMENTATION
PR / MERGE / DEPLOYMENT: NO
MIGRATION / DATABASE_MUTATION: NO
ROUTE_BEHAVIOR_CHANGE / AUTH_POLICY_CHANGE: NO
DEPENDENCY / LOCK / WORKFLOW CHANGE: NO
REAL_ENV_CHANGE: NO
```

## Architecture Position

`config/erp_shell_navigation.php` remains the only Laravel runtime menu configuration. `menu/APPROVED_MENU.json` is the Owner-approved governance baseline and is never loaded by the application at runtime.

The baseline governs only level 1 and level 2. Level-3 pages, detail routes, tabs, filters and saved views remain outside this package. No menu registry, JSON loader, schema service, tree-builder refactor, Artisan command, CI workflow or admin editor was introduced.

## Approved Baseline

```text
VERSION: 1.0
STATUS: OWNER_APPROVED
APPROVED_AT: 2026-08-03
APPROVED_HEAD: 8fe6ba786af1d794b63819ea94aaa45cecd1e1e4
GOVERNED_LEVELS: [1, 2]
LEVEL_1_COUNT: 11
LEVEL_2_COUNT: 0
```

| Sort | Code | Label | URL | Route | Permission | Emoji | Status / Progress |
|---:|---|---|---|---|---|---|---|
| 10 | dashboard | Dashboard | /dashboard | dashboard | dashboard.view | 📊 | in_progress / 20 |
| 20 | logistics | 物流 | /logistics | shell.logistics | logistics.view | 🚚 | planned / 0 |
| 30 | sync | 同步 | /sync | shell.sync | sync.view | 🔄 | planned / 0 |
| 40 | customers | 客户 | /customers | shell.customers | customers.view | 👥 | planned / 0 |
| 50 | kol | 网红 | /kol | shell.kol | kol.view | ⭐ | planned / 0 |
| 60 | inventory | 库存 | /inventory | shell.inventory | inventory.view | 📦 | planned / 0 |
| 70 | procurement | 外采 | /procurement | shell.procurement | procurement.view | 🛒 | planned / 0 |
| 80 | products | 产品 | /products | shell.products | products.view | 🏷️ | planned / 0 |
| 90 | guides | 指南 | /guides | shell.guides | guides.view | 📖 | planned / 0 |
| 100 | accounting | 会计 | /accounting | shell.accounting | accounting.view | 🧾 | planned / 0 |
| 110 | settings | 设置 | /settings | shell.settings | system.permissions.manage | ⚙️ | planned / 0 |

The approved Chinese function descriptions are stored verbatim in `APPROVED_MENU.json`. No level-2 item was inferred from prototype routes.

## Governance Gate

`AGENTS.md` requires every task that may affect a governed menu to read `menu/README.md` and `menu/APPROVED_MENU.json` before changing labels, Emoji, slugs, URLs, routes, permissions, parent relationships, order, function scope, status or progress.

Without explicit Owner authorization, any conflict stops with:

```text
MENU_BASELINE_CHANGE_REQUIRES_OWNER_APPROVAL
```

With authorization, the approved JSON, runtime configuration, automated test and Work Package/change record must change together.

## Independent Review Remediation

```text
INDEPENDENT_REVIEW_RESULT: CHANGES_REQUIRED
REVIEWED_HEAD: 5f79814ce72e02f254d17a533c4606efe5f8adc1
REMEDIATION_BASE: 5f79814ce72e02f254d17a533c4606efe5f8adc1
REMEDIATION:
- Slug added explicitly to AGENTS gate
- Slug added explicitly to README governance
- Slug format validation added
- Slug-to-URL consistency added
- Slug added to runtime baseline comparison
APPROVED_MENU_JSON: UNCHANGED
RUNTIME_MENU_CONFIG: UNCHANGED
```

## Automated Contract

`ApprovedMenuBaselineTest`:

- requires the file and valid JSON metadata;
- validates exact governed levels `[1, 2]`;
- validates all required fields and allowed status values;
- validates unique code, URL and sibling sort;
- validates parent/level integrity and non-empty label/function;
- validates Slug type, non-empty value, lowercase kebab-case format, absence of slash/space and equality to the final URL segment;
- validates integer progress from 0 through 100;
- requires exactly 11 level-1 and zero level-2 items now;
- requires all 11 approved Emoji and order;
- recursively flattens runtime navigation only through level 2;
- ignores level 3 and deeper structures;
- compares code/key, parent, level, label, derived Slug, URL/path, route, permission, Emoji and sibling sort;
- includes `APPROVED_MENU_BASELINE_MISMATCH` in mismatch assertions;
- never accesses or mutates the database.

## Exact File Boundary

```text
NEW ONLY:
AGENTS.md
menu/README.md
menu/APPROVED_MENU.json
tests/Feature/ApprovedMenuBaselineTest.php
docs/60_Codex_Operations/work-packages/ERP-MENU-BASELINE-001.md
docs/60_Codex_Operations/logs/2026-08-03_ERP-MENU-BASELINE-001.md
```

Runtime menu, Account Dock, TopBar, Emoji UI, routes, permissions, application code, dependencies, database and environment remain unchanged.

## Validation Evidence

```text
BASELINE_TEST: 4 tests / 213 assertions / PASS
FULL_PHP: 134 tests / 1322 assertions / PASS
FRONTEND: 12 files / 70 tests / PASS
TYPECHECK: PASS
BUILD: PASS
PINT: PASS
PHP_TEST_ENVIRONMENT: command-scoped ERP_LOCAL_ADMIN_BYPASS=false; real .env unchanged
DATABASE_MUTATION: NO
```

## Rollback

Revert the single baseline commit, which removes only the six new files. There is no runtime, route, permission, dependency, database or environment rollback.

## Risks

- The JSON baseline is governance input, not runtime truth; authorized menu changes must update both sources atomically.
- The comparison intentionally ignores level 3 and deeper structures.
- Current absence of approved level-2 items is a deliberate Owner baseline, not evidence that prototype subroutes are removed.
- Sibling sort is derived from current runtime order in increments of ten; future authorized reorder must update both sources.

## Hours

```text
ESTIMATED_EFFECTIVE_HOURS: 3–5h
PLANNING_VALUE: 4h
ACTUAL_EFFECTIVE_HOURS: 3.2h
ACTUAL_BREAKDOWN: baseline extraction 0.5h; governance files 0.8h; contract test 1h; validation/remediation 0.5h; records and scope audit 0.4h
VARIANCE: -0.8h / -20%
OWNER_REVIEW / MERGE / UAT / DEPLOYMENT: EXCLUDED
```

## Verdict

```text
FINAL_STATUS: ERP_MENU_BASELINE_001_SLUG_REMEDIATION_COMPLETE_PENDING_INDEPENDENT_REVIEW
NEXT_GATE: independent review; PR remains unauthorized
```

## PROJECT POSITION

```text
CURRENT_WORK_PACKAGE: ERP-MENU-BASELINE-001
PACKAGE_STATUS: SLUG REMEDIATION COMPLETE / PENDING INDEPENDENT REVIEW
BASELINE: 8fe6ba786af1d794b63819ea94aaa45cecd1e1e4
GITHUB_STATE: implementation branch only; no PR
GLOBAL_GATE: FAIL
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: independent remediation review and separate Owner PR authorization
EVIDENCE_AS_OF: 2026-08-03
```
