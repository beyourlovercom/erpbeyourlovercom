# ERP-DOMESTIC-SKU-MERGED-EXPORT-20261116

- Status: OWNER DESIGN APPROVED / IMPLEMENTATION AUTHORIZED / UAT PENDING
- Authority: Owner uploaded 17-column sample and proposed button placement; replied “合理，开始执行” on 2026-11-16.
- EXECUTION_PROVIDER: GPT_WEB
- VERIFICATION_TIER: DAILY
- Worktree: /Users/a/ai/uat/erp-domestic-sku-merged-20261116
- Branch: feat/domestic-pending-sku-merged-20261116
- Baseline: origin/main abcf15796eabde6f17c6b890a9a6059c5bb2ae55
- Dependencies: PR #786 merged in baseline; no unmerged PR dependency.
- Parallel-safe: dedicated worktree; changes limited to pending-batch export, route, pending toolbar, and focused tests.
- Layout: existing compact pending-batch actions; secondary download link left of existing primary download.
- Scope: read-only XLSX download for 国内发货 → 待发货, separate endpoint, same current pending-ready query and logistics.view permission.
- 17 columns: ERP订单号, 客户单号, 物流商, 服务, Tracking, 收件人, 电话, Email, 国家/地区, 州/省, 城市, 邮编, 地址, SKU, 数量, 金额, 物流备注.
- Per order one row; all items sort by item id; SKU and corresponding quantity lines join with newline, retaining literal SKU spelling/identity.
- Never aggregate distinct orders or collapse duplicate SKUs; no new table/data state.
- Original pending-batch XLSX and tracking-upload workflow remain unchanged.
- Empty or ineligible order set returns existing 422 behavior. No Production deployment.
- Acceptance: exact headers/positions, two-SKU multiline cell and quantity one-to-one match, third row unaffected; output no formulas from user data.
- Acceptance: route access permissions, pending-only UI entry; existing dynamic SKU1/SKU2 original still passes regression.
- Validation: focused Laravel PHPUnit, Vue/Vitest UI, php lint, typecheck/build and workspace-tabs verifier, then real Local/UAT Owner PASS.
- UI: BYL_COMPACT_WORKSPACE_V1; Page Header Action Zone unaffected, existing pending workbench action cluster extended, no tabs/layout overrides.

PROJECT POSITIONPROGRAM_ROADMAP: Architecture Gate → Inquiry/Order/Product/Inventory/MVP1 → Woo/Tracking/Sync → KOL → Logistics/Accounting/Customer → Fulfillment Intelligence → Remote Release/Hardening (historical canonical plan docs/10_Planning_Roadmap/13_PROGRAM_ROADMAP_AND_HOURS.md).
CURRENT_TRACK_PHASE_MAP: R0 sample + code baseline COMPLETE → R1 implementation CURRENT → R2 regression WAITING → R3 local Owner UAT WAITING → R4 Git delivery WAITING → Production separate authorization.
COMPLETED_PHASES: R0
CURRENT_PHASE: R1
CURRENT_WORK_PACKAGE: ERP-DOMESTIC-SKU-MERGED-EXPORT-20261116
PACKAGE_STATUS: IMPLEMENTATION_AUTHORIZED
WAITING_PHASES: R2, R3 Owner UAT, R4 delivery, Production release
BASELINE: main abcf15796eabde6f17c6b890a9a6059c5bb2ae55
GITHUB_STATE: branch not pushed; PR pending
SOURCE_CONFLICTS: older AI/CURRENT_CONTEXT historical phase snapshot; current GitHub main + Owner 2026-11-16 scope prevail
GLOBAL_GATE: OWNER_DESIGN_PASS; OWNER_LIVE_UAT_PENDING; PROD_NOT_AUTHORIZED
PRODUCTION_DEPLOYMENT_STATUS: NO; read-only development package
NEXT_DEPENDENCY: implement and run tests; Owner real Local/UAT PASS
EVIDENCE_AS_OF: 2026-11-16

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 2–4 effective engineering hours (planning 3h)
KNOWN_CURRENT_TRACK_REMAINING: package R1–R4 only, 2–4h before accepted actuals
ARCHITECTURE_GATE_REMAINING: 0h (global architecture gate completed)
MVP1_TOTAL_ESTIMATE: historical 122–190h
MVP1_PLANNING_BASELINE: historical 166h
MVP1_MAXIMUM_ENVELOPE: historical 192h
MVP1_REMAINING: UNKNOWN WITH EVIDENCE (ledger overlap incomplete)
POST_MVP_KNOWN_INCREMENT: this package 2–4h, not added to historical MVP1
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE (global programme unbounded)
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE (work log not reconciled)
ESTIMATE_CONFIDENCE: medium
ESTIMATE_AS_OF: 2026-11-16
ESTIMATE_METHOD: 1 exporter + 1 route/controller + 1 UI link + focused tests, local runtime and Git review

ESTIMATED CODEX HOURSPREFLIGHT_AND_SCOPE: 0.4h
CORE_EXECUTION: 1.4h
TEST_AND_VALIDATION: 0.8h
REMEDIATION_BUFFER: 0.4h
ESTIMATED_EFFECTIVE_HOURS: 3.0h
ESTIMATE_RANGE: 2–4h
ESTIMATE_BASIS: baseline has existing pending-batch exporter and query; reuse with separate SKU presentation.
OWNER_REVIEW: EXCLUDED
MERGE: INCLUDED (after required Owner UAT)
MIGRATION: EXCLUDED
UAT: INCLUDED (technical setup only; Owner time excluded)
DEPLOYMENT: EXCLUDED
