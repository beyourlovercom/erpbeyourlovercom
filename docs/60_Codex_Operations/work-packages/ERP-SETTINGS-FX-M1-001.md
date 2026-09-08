# ERP-SETTINGS-FX-M1-001

Status: OWNER UAT PASS / MERGE AUTHORIZED
Owner Visual Gate: PASS (2026-09-07)
Owner Interaction Revision: APPROVED / EXECUTE (2026-09-08) — per-currency slide switch; automatic ON = green; manual OFF = gray; switching to automatic preserves the stored manual value.

## Baseline
- Repository: `beyourlovercom/erpbeyourlovercom`
- Branch: `feature/erp-settings-fx-m1-001`
- Worktree: `/Users/a/AI/007/worktrees/erp-settings-fx-m1-001`
- Base after final 2026-09-08 reconciliation: `origin/main@e37d5fa4827384d407ea81e14ada8335e11e87f5`
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

## Final verification / closure evidence
- Owner visual proposal: PASS.
- Owner live-page UAT: PASS (2026-09-08) — Owner response: “完美。可以封口。”
- Final affected PHP regression after latest-main reconciliation: FX/ECB/AppShell PASS; the only Approved Menu failures are inherited from main's newly merged `accounting-loss-orders` query-URL baseline mismatch and level-2 count drift.
- Frontend FX + Settings tests: PASS.
- TypeScript: PASS.
- Vite production build: PASS.
- GitHub Actions did not start because account billing/spending-limit blocked jobs; annotations explicitly report billing, not code/test failure.
- `git diff --check`: PASS.

## Hard gates
- Owner visual proposal: PASS.
- Owner live-page UAT: PASS.
- Merge: OWNER AUTHORIZED by closure instruction on 2026-09-08.
- Production deploy/schema migration: separate Owner Gate; NOT AUTHORIZED by this closure.
