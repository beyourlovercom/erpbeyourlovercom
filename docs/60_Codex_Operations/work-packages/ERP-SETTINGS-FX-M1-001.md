# ERP-SETTINGS-FX-M1-001

Status: IMPLEMENTATION / LOCAL VERIFICATION
Owner Visual Gate: PASS (2026-09-07)
Owner Interaction Revision: APPROVED / EXECUTE (2026-09-08) — per-currency slide switch; automatic ON = green; manual OFF = gray; switching to automatic preserves the stored manual value.

## Baseline
- Repository: `beyourlovercom/erpbeyourlovercom`
- Branch: `feature/erp-settings-fx-m1-001`
- Worktree: `/Users/a/AI/007/worktrees/erp-settings-fx-m1-001`
- Base after 2026-09-08 reconciliation: `origin/main@cf72a2eadb489ff27cf6b21700d7c86d6b4686ca`
- Execution provider: GPT_WEB
- Verification tier: DAILY / focused affected regression + build

## Objective
Add one dedicated Settings FX workspace at `/settings/fx` for management/reference currency conversion to RMB, while preserving the existing ECB reference-rate behavior and the Accounting transaction-FX boundary.

## Owner-approved scope
- add Settings level-2 menu item `汇率计算` → `/settings/fx`;
- reuse existing `EcbFxService` for ECB reference rates and its 12-hour/stale-cache policy;
- support USD, EUR, GBP, CAD, AUD and PHP manual rates expressed as `1 foreign currency = ? CNY`;
- CNY remains fixed at 1.0;
- each editable currency has an explicit automatic/manual mode switch;
- automatic mode uses ECB while preserving any saved manual rate; switching back to manual reuses that preserved value;
- expose quick management calculator and automatic-vs-manual comparison table;
- persist manual rates separately with actor traceability;
- permission = `system.permissions.manage`;
- update approved menu baseline, runtime navigation and focused tests together.

## Finance boundary
- this page is management/reference configuration only;
- it MUST NOT write Order revenue, cost, profit or historical financial facts;
- it MUST NOT become accounting transaction-FX authority;
- historical/posting facts keep their transaction/posting FX snapshot semantics;
- Accounting base currency remains USD;
- no historical P&L is revalued when a current manual/ECB rate changes.

## Data model
`finance_fx_manual_rates`
- `currency` primary key;
- `manual_rate_to_cny` decimal(18,8);
- `use_auto` boolean mode flag;
- nullable `updated_by` user FK;
- timestamps.

No saved row means automatic ECB mode. A saved manual value may remain stored while `use_auto = true`; mode switching never clears that value.

## Verification
Required before Owner UAT:
- frontend FX + Settings tests;
- TypeScript typecheck;
- Vite production build;
- FX feature tests covering permissions, validation, persistence, explicit mode switching, and preservation of manual values in automatic mode;
- existing `EcbFxService` regression;
- approved-menu/runtime-navigation regression;
- PHP syntax checks for touched/new PHP;
- `git diff --check`.

## Inherited architecture baseline drift
`tests/Architecture/ModuleArchitectureTest.php` already fails on the exact `origin/main@813f9fe4...` source tree because its frozen directory/file sets lag substantial previously merged modules (including Procurement, NotificationCenter, KOL/Logistics additions and existing Finance contracts). This WP does not take ownership of repairing that global 100+ file architecture baseline debt. The FX-specific module boundaries are verified separately and this WP must not weaken or broadly rewrite the architecture test.

## Hard gates
- Owner visual proposal: PASS.
- Owner live-page UAT: PENDING.
- Merge: after Owner UAT + current-main reconciliation + checks.
- Production deploy/schema migration: separate Owner Gate; not authorized by this WP alone.
