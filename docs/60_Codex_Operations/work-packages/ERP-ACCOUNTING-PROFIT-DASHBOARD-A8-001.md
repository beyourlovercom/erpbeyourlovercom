# ERP-ACCOUNTING-PROFIT-DASHBOARD-A8-001

## Scope and authority

Accounting / Order Profit V1 A8 adds the real `/accounting` overview while preserving `/accounting/order-costs` as the single per-order detail workspace and formula authority. The Owner visual proposal was approved before implementation (`OWNER_VISUAL_PASS: PASS`). Production deployment and Production data access are not authorized.

## Data contract

- Operational scope is customer orders only, excluding orders carrying the `historical_logistics` external reference.
- Dashboard filters are date range (default last 30 days), country, source type, and product/SKU; the same supported filters are carried into detail drilldowns.
- Monetary KPIs and aggregation tables consume only `OrderAnalysisService` summaries with `CONFIRMED` or `RECONCILED` finance status and confirmed profit status.
- `ESTIMATED`, `INCOMPLETE`, and `UNPOSTED` orders never enter confirmed monetary totals. Non-USD orders enter USD totals only when the summary reports `base_currency_aggregate_eligible`; otherwise they remain FX pending.
- Confirmed loss count delegates to the Finance-owned `ConfirmedLossReader` / A7 `ConfirmedLossProjection`.

## Aggregation semantics

The overview exposes confirmed revenue, cost, profit, margin, confirmed loss orders, profit completeness, data-health counts, daily trend, and country/product/source tables. Product totals allocate only confirmed, USD-aggregate-eligible order-level totals. Actual `line_revenue` values are used as weights; when every line revenue is missing or zero, the confirmed order totals are split equally across item lines. Allocation is performed in integer USD cents with deterministic remainder distribution so item allocations conserve the exact order total. No missing fact is converted to zero and no new order-profit formula is introduced.

Each aggregate row contains a supported-filter URL to `/accounting/order-costs`. The right-side Finance fact drawer behavior is unchanged.

## Navigation

Accounting is implemented at `/accounting`; children are `概览` and `订单利润`. The visible old `每一单成本表` label is renamed while `/accounting/order-costs` remains stable.

## Validation evidence

Local validation is complete on the US Mac. `ProfitDashboardTest` passes 4 focused tests / 105 assertions covering confirmed-only USD KPIs, incomplete/estimated/historical/FX-pending exclusion, frozen-FX inclusion, exact filter/drilldown scope, `ConfirmedLossReader` authority delegation, line-revenue allocation, equal fallback, and integer-cent conservation. `OrderProfitV1Test`, `ApprovedMenuBaselineTest`, and the updated App Shell navigation coverage pass. `npm run typecheck`, `npm run build`, PHP syntax checks, and `git diff --check` pass. Five architecture behavior/contract tests pass with 33 assertions (`domain/application/infrastructure`, cross-module, namespace, provider, tokenizer). The repository's global frozen application/migration lists remain stale from unrelated prior Logistics/KOL/Notification changes; the same `layer_scans` and `MIG-001` failures reproduce on the A7 baseline worktree, so A8 does not rewrite that unrelated frozen-list drift. Local dependencies are provided by identical-lockfile symlinks solely for validation and are gitignored.

PRODUCTION_NOT_AUTHORIZED: YES
PRODUCTION_DATA_TOUCHED: NO
OWNER_LOCAL_UAT_PASS: PASS
OWNER_LOCAL_UAT_PASS_DATE: 2026-09-07
OWNER_UAT_SURFACE: /accounting on isolated SQLite runtime 127.0.0.1:18134
OWNER_UAT_VI: KPI cards + single-day trend fallback + FX-pending health emphasis accepted

## Project position

PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate → MVP1 Existing Operations / Tencent Docs Replacement → ERP-INQUIRY-M1 B2B Inquiry Foundation → ERP-INQUIRY-M1 Local Milestone UAT → Milestone-gated Remote Release → MVP1.1 WooCommerce Inbound → MVP1.2 Single Tracking Outbound → MVP1.3 Sync Orchestration → Product / Media → WordPress Product and Image Sync → Content / SEO → Posts / Pages → GA4 / GSC / Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS and Social Providers → Inventory and Procurement → Accounting / ROI Expansion → Shopify and Additional Integrations → Production Hardening / Expansion
CURRENT_TRACK_PHASE_MAP: Accounting Order Profit V1 A8 dashboard / aggregation implementation → validation COMPLETE → Owner Local/UAT CURRENT
COMPLETED_PHASES: A7 Confirmed Loss Orders and A7 order-cost detail authority
CURRENT_PHASE: Accounting / ROI Expansion
CURRENT_WORK_PACKAGE: ERP-ACCOUNTING-PROFIT-DASHBOARD-A8-001
PACKAGE_STATUS: OWNER LOCAL/UAT PASS / MERGE CANDIDATE
WAITING_PHASES: Owner Local/UAT PASS → delivery commit/PR/merge gate
BASELINE: current branch feature/erp-accounting-profit-dashboard-a8-001 at origin/main baseline
GITHUB_STATE: local branch only; no commit or push requested
SOURCE_CONFLICTS: Pre-existing architecture frozen-list drift remains in ModuleArchitectureTest; identical layer-scan/MIG-001 drift reproduces on the A7 baseline and is outside A8 scope. A8-specific architecture behavior tests PASS.
GLOBAL_GATE: OWNER_VISUAL_PASS=PASS; implementation scope approved
PRODUCTION_DEPLOYMENT_STATUS: NO WITH EVIDENCE — no Production command or data access performed
NEXT_DEPENDENCY: reconcile latest main, run final merge-candidate validation, then PR / CI / merge
EVIDENCE_AS_OF: 2026-09-07

## Roadmap hours

CURRENT_PACKAGE_ESTIMATE: 10 effective hours, range 8–14
KNOWN_CURRENT_TRACK_REMAINING: UNKNOWN WITH EVIDENCE — only this A8 package scope was supplied
ARCHITECTURE_GATE_REMAINING: 0; architecture baseline is complete per AI/CURRENT_CONTEXT.md
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE — current roadmap source does not provide a safe A8-subtracted total here
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE
MVP1_REMAINING: UNKNOWN WITH EVIDENCE
POST_MVP_KNOWN_INCREMENT: 10 effective hours planning value for this package
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE — no accepted Work Log ledger supplied
ESTIMATE_CONFIDENCE: medium
ESTIMATE_AS_OF: 2026-09-07
ESTIMATE_METHOD: bounded file/change/test estimate from the supplied A8 contract

## Estimated Codex hours

PREFLIGHT_AND_SCOPE: 1.5 hours (1–2)
CORE_EXECUTION: 5.5 hours (4–8)
TEST_AND_VALIDATION: 2 hours (1.5–3)
REMEDIATION_BUFFER: 1 hour (1–1)
ESTIMATED_EFFECTIVE_HOURS: 10 hours
ESTIMATE_RANGE: 8–14 hours
ESTIMATE_BASIS: frozen Finance summary/loss authorities, new Finance dashboard aggregation, navigation contract, responsive page, tests and required validation
OWNER_REVIEW: INCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: INCLUDED
DEPLOYMENT: EXCLUDED
ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE — no accepted Work Log ledger supplied; local validation is complete
ACTUAL_HOURS_BREAKDOWN: UNKNOWN WITH EVIDENCE — no accepted Work Log ledger
VARIANCE: UNKNOWN WITH EVIDENCE
VARIANCE_REASON: UNKNOWN WITH EVIDENCE — actual effective hours are not available from an accepted Work Log ledger

## Final merge-candidate evidence — 2026-09-07

- Owner Local/UAT: PASS on isolated SQLite runtime `/accounting` at `127.0.0.1:18134`.
- Finance Dashboard + Order Profit + approved-menu + AppShell focused gate: 30 tests / 1819 assertions PASS after latest-main reconciliation.
- Architecture behavior rules (domain/application/infrastructure, cross-module, namespace/path, provider): 4 tests / 28 assertions PASS.
- `npm run typecheck`: PASS.
- `npm run build`: PASS.
- `git diff --check`: PASS.
- Known non-A8 red item: repository-wide frozen layer/file list drift remains reproducible on the A7 baseline and has expanded with later Inventory/Logistics mainline files; not remediated in this WP.
- Latest main reconciled before merge candidate; no A8 file overlap with intervening Inventory/Logistics changes.
- Production deployment/migration/data write remains NOT AUTHORIZED.
