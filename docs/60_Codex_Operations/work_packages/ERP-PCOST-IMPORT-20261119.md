# ERP-PCOST-IMPORT-20261119 — Monthly SKU Product Cost Import V1 Work Package

STATUS: OWNER DESIGN PASS / P0-P2 IMPLEMENTED / P3 ISOLATED BROWSER SMOKE PASS / LIVE OWNER UAT PENDING / NO PRODUCTION WRITE
Date: 2026-11-19 (Asia/Hong_Kong). Repository: beyourlovercom/erpbeyourlovercom.
Worktree branch: feat/product-cost-import-20261119, originating main 078de3ecdebc1b9dbbc26f29717e0f4833af55bc.
EXECUTION_PROVIDER: GPT_WEB
TRANSPORT: BYL_SELF_HOSTED_DC_REMOTE / us-macmini. Owner explicitly corrected execution transport; Claude Code session is signed out and was not used for implementation. GPT performs bounded implementation and Dev verification directly through DC.
VERIFICATION_TIER: HIGH_RISK
Owner authorization: "效果pass 推进执行" after interactive GPT Site prototype; covers Dev implementation and test only. Does not authorize merge, Production deployment or Production data writes.
Design source: AI/ERP_PRODUCT_COST_IMPORT_V1_OWNER_BRIEF.md; original approved interaction: https://byl-erp-cost-import-lab.warmmesa1027.chatgpt.site.

## Business objective and source authority

One recurring in-ERP importer for any month, not just October: extract original CNY unit costs from 007 进销存 Excel/CSV, normalize manufacturer descriptions into base ERP SKUs, resolve conflicts/unmatched rows with deliberate operator confirmation, write confirmed CNY cost evidence to existing Products canonical product_cost_sources, display USD via existing accounting FX, and let future eligible order profit read confirmed effective-dated product costs through existing CostMaterializationService and ProductCostEvidenceReader. Never create a second canonical cost ledger.
Observed offline sample, NOT current ERP truth: 2026 Feb–Oct source yields 942 valid lines / 94 base SKUs, 15 conflicts and 23 unmatched against a 245-SKU US local snapshot; re-query live Dev products on import.

## Exact implementation scope

1. Accept bounded XLSX and CSV file uploads in existing Laravel/Inertia ERP Products area. Detect multi-sheet 进销存 month/year and columns with explicit schema validation and clear unsupported-template errors. Feb name C/cost E; Mar name A/cost G; Apr–Oct name A/long code B/cost H are observed layouts, not universal assumptions. Preserve original row numbers, source sheet, month, name, code, raw cost and exclusions.
2. Normalize base SKU (e.g. VA617A（国内新版） -> VA617A; 1BY489A5C0100 -> BY489A) deterministically with tests. Match by exact canonical products SKU/product ID; suggestions require human selection; do not fuzzy auto-approve ambiguous/composite variants.
3. Provide native page with file chooser, overview stats, filterable global data grid, conflict/unmatched row confirmation, ERP SKU search/select, choosing one original cost or manually entering actual CNY cost, source/evidence and mandatory reason, effective month, and dry-run/confirm. Preserve supplied original observations and user override audit; no silent overwrites.
4. Server-side validated plan-first application using ConfirmedProductCostSourceImportService. Revalidate file/row/product/conflict at confirm time; idempotent reimport by fingerprint; require existing authentication, permission, CSRF, safe file size/MIME. Do not bypass product_cost_sources uniqueness, period overlap checks or guarded confirms.
5. USD display is calculated from CNY with actual accounting FX evidence (rate/date/source) through existing Finance semantics; unavailable FX => visible blocker. Confirmed CNY remains cost truth; never store demonstration FX or another USD truth.
6. Future order cost/profit uses existing effective-date, product evidence and immutable accounting FX mechanism; verify with focused tests. Do not silently reprice or alter historical fulfilled/reconciled orders.
7. Preserve approved menu baseline; enter from existing Products header action or already permitted cost route. Native Vue/Inertia, existing tokens, form design standard, upper-right page actions and Global Data Grid VI v1.1. Do not alter Level 1/2 menu.
8. Prepare an isolated US Mac real Dev UAT at http://erp.b. Branch/draft PR only after focused validation; stop before Owner live Dev PASS. No merge or Production deploy in this package.

## Explicit prohibitions and dependencies

No Production or remote ERP DB writes, migration execution in Production, deployment, historical order backfill, reconciliation rewrite, real OMS test orders, invented FX rate, hidden fuzzy auto-match or automatic application of unresolved conflicts. Database schema change only if demonstrably necessary; then treat as additional HIGH_RISK migration review and local disposable DB validation. Existing UI component and product/finance contracts are dependencies. One package / one branch / one PR / one writer. Claude must not run unapproved subagents or expand package. Claude works as implementation provider; GPT performs diagnosis/diff review, tests/final verification owner gate.

## Acceptance / validation contract

A. Multi-month sample extracts every valid cost-bearing product line, preserves traceability and skips transaction totals; independently tested across observed month templates, bad dates, corrupted files, duplicate tabs and absent cost fields.
B. VA617A Chinese-version suffix normalizes to VA617A and exact matching selects correct ERP product; long-code and ambiguous variants are tested. Unmatched entries can be manually mapped without writing guessed product IDs.
C. Same-month differing costs produce a visible conflict. Operator can choose imported version or enter an actual CNY amount; mandatory reason/evidence, permission and audit are enforced. No write before confirm and no write if unresolved.
D. A preflight dry-run reports ready/conflict/unmatched/missing FX/existing period errors with stable preview; confirm revalidates against changed ERP product/cost records and safely refuses stale plans.
E. First confirmed import creates canonical CNY source with correct effective month, no duplicate on replay, and existing conflicting confirmed source is blocked rather than overwritten.
F. USD preview states FX rate/date/source and matches Finance accounting conversion for CNY; missing FX is blocked. Existing order profit path uses applicable product cost on newly eligible orders; historical frozen orders unchanged.
G. Focused PHP unit/feature tests, Vue interaction tests if supported, typecheck, production frontend build, permission and route checks, one real rendered local Chrome UAT. Verify database transaction rollback and failure handling. Record pre-existing failures separately.
H. Owner visually inspects the actual local ERP Dev page and issues a new live UAT PASS before merge. Production release requires another distinct authorization.

## Execution plan / checkpoints

P0 Contracts and fixture parser + normalization + tests. P1 canonical backend plan/confirmation and audit + tests. P2 Inertia page and USD evidence + frontend tests. P3 end-to-end local verification + real UAT. P4 draft PR and Owner stop gate. Each phase stays within this package; report exact SHA/test evidence and blockers. Rollback: disable or revert new routes/page/importer; no confirmed Production records are created in this package.

## P0 real-source reconciliation checkpoint — 2026-11-19

Source SHA-256 6428cd351a5adbf40aad493ac52b3946e906ff0b24936544841ef079a8bdf77f transferred by BYL DC to US Mac temporary storage only; original workbook is not tracked. Current parser after conservative transaction filtering: 954 reviewable monthly cost rows, 78 excluded/review-required rows, 94 distinct base SKUs exactly equal to the prior 94-SKU set. Prior summary contained 942 rows: 14 extra reviewable source observations in new parser (pillow variants, SF030C, some un-coded rows) and 2 previously included transaction-like rows now explicitly excluded. Difference is classification of source evidence, not 12 invented SKU costs. Excel 10月 contains saved VLOOKUP formula results; parser keeps formulas and saved values separately, never recalculates arbitrary workbooks. No live ERP product or FX confirmation has occurred yet. Focused PHP tests 15/15 PASS with 58 assertions; Vue typecheck and Vite build PASS; real Dev browser/UAT PENDING. Dev write remains disabled by default and Production always blocked.

## P1–P3 implementation and local verification checkpoint — 2026-11-19

Implemented deterministic base SKU extraction; multi-month XLSX/XLS/CSV original-unit-cost parsing; guarded ERP resolution, conflicting-cost choice and manual CNY override with mandatory reason; canonical monthly product_cost_sources adapter and audit; native Inertia Global Grid importer and drawer; read-only USD reference cost catalog (100 per page with SKU/month filter); read-only excluded-record evidence table. Default Dev apply flag is OFF and Production confirm is always blocked. No database migration required.

Local focused PHPUnit verification **32 tests / 177 assertions PASS** across source parser, SKU extractor, resolver, new HTTP import/cost-catalog routes, simulated Dev successful confirmation with user audit, Production flag guard, and existing Finance USD frozen-FX and effective-date contract. TypeScript typecheck PASS, Vite/30-tab build PASS, five routes registered. Pint applied to only new files and modified route; existing Finance test style baseline is not expanded. Existing OrderProfitShow behavior remains otherwise unchanged.

US Mac mini isolated Playwright Chromium login/render smoke, against **disposable local SQLite** at 127.0.0.1:18861, PASS: auth, new page, product USD catalog, original exact-SHA 007 workbook upload, 954 source rows, 706 base-SKU/month groups, 133 groups containing differing source prices, 78 excluded source rows; open quick-confirm drawer and enter manual CNY cost/reason; 390px mobile viewport body width 390/no horizontal overflow; no browser JS exceptions. Catalog's two synthetic VA617A rows and manually configured USD/CNY 7.10 rate are **test fixtures only**, not live ERP source truth. The only fixture ERP SKU means all 706 groups remain blocked in that isolated sandbox until a real ERP Dev product catalog is present; do not treat it as a live match result. Synthetic database contains no orders.

Actual Owner inspection of an accessible ERP Dev URL is PENDING; the local-only 127.0.0.1 port cannot be presented as a public Owner-UAT endpoint. No push/merge/Production deployment in this checkpoint; P4 draft PR follows local verification as a review artifact but does not unlock merge. No Production records were read or changed by this smoke.

PROJECT POSITION
PROGRAM_ROADMAP: Architecture Foundation [COMPLETE] -> Inquiry M1 Foundation [COMPLETE] -> Order Fulfillment M1 [COMPLETE] -> Product Master/SKU M1 [COMPLETE] -> Inventory/Batch M1 [COMPLETE] -> MVP1 manual fulfillment [COMPLETE] -> WooCommerce inbound/tracking/sync [COMPLETE] -> KOL core and operational expansion [COMPLETE at frozen scopes] -> GitHub/Production Truth Reconciliation -> Production Stability/Audit -> Warehouse Bill Coverage -> Inventory/Fulfillment Hardening -> Accounting Completeness -> remaining Product/Media/Content/SEO and platform integrations; docs/10_Planning_Roadmap/13_PROGRAM_ROADMAP_AND_HOURS.md retains complete historical horizon.
CURRENT_TRACK_PHASE_MAP: ERP-PCOST-IMPORT-20261119 P0 parser/contract [COMPLETE] -> P1 canonical backend/audit [COMPLETE] -> P2 Vue/FX/confirmed USD catalog [COMPLETE] -> P3 US local isolated rendered smoke [COMPLETE], Owner live ERP Dev UAT [WAITING] -> P4 draft PR review [NEXT], merge gate [WAITING].
COMPLETED_PHASES: Approved GPT Site prototype; original Excel SKU/source reconciliation; P0-P2 implementation, 32 focused tests, typecheck/build, local disposable SQLite + authenticated Playwright browser smoke.
CURRENT_PHASE: P3 Owner-accessible live ERP Dev UAT preparation; P4 draft PR review artifact.
CURRENT_WORK_PACKAGE: ERP-PCOST-IMPORT-20261119.
PACKAGE_STATUS: IMPLEMENTATION AND LOCAL VERIFICATION PASS / LIVE OWNER ERP DEV UAT PENDING / MERGE NOT AUTHORIZED.
WAITING_PHASES: P3 Owner visual real ERP Dev UAT; P4 draft PR review and eventual Owner merge gate; Production release separately gated.
BASELINE: GitHub main 078de3ecdebc1b9dbbc26f29717e0f4833af55bc verified 2026-11-19; branch originally same HEAD.
GITHUB_STATE: Main SHA above; no package PR yet; local isolated worktree not pushed.
SOURCE_CONFLICTS: AI/CURRENT_CONTEXT.md (2026-08-29) says KOL V1 current; 13_PROGRAM_ROADMAP_AND_HOURS.md overlay (2026-09-19) says Production reconciliation/stability; both predate GitHub main 2026-11-19. This package is an explicit new Owner-approved Product/Finance priority; mutable global program sequencing remains UNKNOWN until separately rebaselined. Old roadmap phases and current main must not be silently conflated.
GLOBAL_GATE: Architecture PASS at frozen scope; package feature gated on focused verification and Owner live Dev UAT.
PRODUCTION_DEPLOYMENT_STATUS: NO WITH EVIDENCE: this worktree/local development has no Production deployment or write authority; not a claim about unrelated existing deployments.
NEXT_DEPENDENCY: Create draft PR after verified local code; provide an Owner-accessible, isolated Dev URL with real ERP test product catalog; obtain explicit Owner visual PASS before merge.
EVIDENCE_AS_OF: 2026-11-19; GitHub main SHA, AI/CURRENT_CONTEXT.md, docs/10_Planning_Roadmap/13_PROGRAM_ROADMAP_AND_HOURS.md, design brief, Owner PASS.

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 17 effective hours planning value; 12–22h estimate range; bounded new non-overlapping feature scope.
KNOWN_CURRENT_TRACK_REMAINING: 12–22h within ERP-PCOST-IMPORT-20261119 only; other historical packages excluded, no double count.
ARCHITECTURE_GATE_REMAINING: 0h, architecture Global Gate complete per roadmap.
MVP1_TOTAL_ESTIMATE: 122–190h historical original total (not current remaining).
MVP1_PLANNING_BASELINE: 166h historical planning value.
MVP1_MAXIMUM_ENVELOPE: 192h historical maximum envelope.
MVP1_REMAINING: UNKNOWN WITH EVIDENCE: accepted actual ledger and overlap map incomplete; current cost importer not added retroactively.
POST_MVP_KNOWN_INCREMENT: Approx. 68–120h historical non-overlapping WooCommerce inbound + tracking outbound increment; not summed into this package.
LONG_TERM_CUMULATIVE_RANGE: 235–402h architecture; 603–1,018h automated operations; 1,059–1,802h Product/SEO; 1,595–2,714h core ERP; 1,819–3,146h all expansion, cumulative alternatives not additive.
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE: accepted actual ledger and package overlap mapping incomplete; no invented subtraction.
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE: docs/10_Planning_Roadmap/13_PROGRAM_ROADMAP_AND_HOURS.md project-wide Work Log ledger incomplete.
ESTIMATE_CONFIDENCE: MEDIUM for feature bounded package, LOW for unresolved integration; HIGH only for quoted historic roadmap totals.
ESTIMATE_AS_OF: 2026-11-19.
ESTIMATE_METHOD: Scope decomposition across P0–P4, no elapsed-time conversion; historical ranges sourced to document 13, not added to or subtracted from package.

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 2–3h.
CORE_EXECUTION: 7–12h.
TEST_AND_VALIDATION: 2–4h.
REMEDIATION_BUFFER: 1–3h.
ESTIMATED_EFFECTIVE_HOURS: 17h planning; total component range 12–22h.
ESTIMATE_RANGE: 12–22h effective engineering time, not wall-clock or billed time.
ESTIMATE_BASIS: XLSX/CSV schema drift, canonical CNY conflict semantics, FX display, UI confirmation, focused tests and US UAT; one branch, no Production.
OWNER_REVIEW: INCLUDED (Dev UAT gate, no duration assumed in engineering budget).
MERGE: EXCLUDED.
MIGRATION: EXCLUDED (no planned database schema migration; reassess and gate if needed).
UAT: INCLUDED (local setup and test evidence; Owner visual PASS remains external stop gate).
DEPLOYMENT: EXCLUDED.
