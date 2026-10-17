# ERP KOL Order UAT remediation — 2026-10-08

## Authority / scope
Owner requested fixes for missing manual KOL ERP numbers and specialist order isolation; explicitly authorized China-machine repair and verification on 2026-10-08. Owner selected linking a canonical KOL to generate the existing k+KOL-ID identity. This does not authorize a new number scheme.
EXECUTION_PROVIDER: GPT_WEB (bounded remediation through Remote Desktop Commander)
IMPLEMENTATION_HOST: CN Mac, Owner override for this package only
VERIFICATION_TIER: HIGH_RISK
WORKTREE: /Users/a/AI/007/worktrees/kol-order-uat-remediation-20261008
BRANCH: fix/kol-order-uat-remediation-20261008
ONE_ACTIVE_IMPLEMENTATION_PROVIDER_PER_WORKTREE: YES
DEPENDENCIES: merged #428
DEPENDENCY_TYPE: MERGED_PR
DEPENDENCY_STATUS: origin/main 4635aee fetched and inspected
PARALLEL_SAFE: YES; no matching open implementation PR; #232 packaging and #97 design excluded
EXPECTED_FILE_SCOPE: Orders model/service/controller; Search contract/providers/service; targeted tests; this package. Numbering UI and exact KOL-link changes wait for visual proposal PASS.
MENU_IMPACT: NO; existing menu/routes retained
APPROVED_MENU_CHECK: PASS

## Evidence and acceptance
- Manual KOL creation supplies no erp_order_id; OrderService allocates only for customer orders.
- Frozen identity rule: k source number is kols.id; no random/order-id substitutes or silent collision suffix.
- Owner-scoped KOL users must see only their own orders through lists, totals, filters, CSV, global search, direct detail/redirect, updates and operational markers.
- KOL all-scope permission remains kol.views.manage; logistics and finance roles without KOL scope retain existing operational access. Do not alter employee role assignments.
- Derived fulfillment rows inherit root order visibility, including deliberately mismatched child owner fixtures.
- Prevent client filter override and SQL OR/search bypass. No front-end-only hiding.
- No UI redesign. Show the proposed KOL selector/number area before UI implementation under AGENTS visual gate.
- Account activation and KOL approval are distinct; no production-account approval claim without read evidence.
- No Production changes, live data repair, fake live orders/tracking, migrations, Woo calls or notifications.

## Validation
Targeted owner/isolation/permission tests, existing OrderFulfillment/Identity/Search/KOL regressions, architecture checks, syntax/style and diff-check. Dependencies must resolve the new worktree, never an older Composer classmap. Synthetic isolated SQLite only. Owner UAT remains pending; no merge until applicable gates pass.

## PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate [COMPLETE] → ERP-INQUIRY-M1 M1.0 Contract + M1.1 Company [COMPLETE] → ERP-ORDER-FULFILLMENT-M1 [COMPLETE] → Product Master / SKU M1 [COMPLETE] → Order Create/Edit V2 [COMPLETE] → Inventory Foundation + Inventory Batch Sync M1 [COMPLETE] → MVP1 Manual Fulfillment Integration [COMPLETE / PR #101] → MVP1.1 WooCommerce Inbound [COMPLETE / PR #104] → MVP1.2 Tracking Outbound [COMPLETE / PR #106] → MVP1.3 Sync Orchestration [COMPLETE / PR #108] → KOL M1.0 Foundation [COMPLETE / PR #103] → KOL M1.1 CRM [COMPLETE / PR #110] → ROADMAP RECONCILIATION [CURRENT / DOCS MERGE GATE] → KOL M1.2A Collaboration [NEXT] → KOL M1.2B Canonical Fulfillment Bridge → KOL M1.3 Content → KOL M1.4 Finance / Affiliate → KOL V1 Cross-phase Closure / Full Regression / Owner UAT → MVP2 Inventory Reservation Foundation + MVP2.1–MVP4 Fulfillment Intelligence / Connectors / Automation [PAUSED UNTIL KOL V1 CLOSED] → Accounting / Procurement expansion → remaining Inquiry milestone when reactivated → Milestone Remote Release / erp.beyourlover.com → Product / Media / Content / SEO / Shopify expansion → Production Hardening
CURRENT_TRACK_PHASE_MAP: logistics core → overseas UAT #427/#428 closed by Owner → five-role UAT → KOL remediation → Owner UAT → production pilot
COMPLETED_PHASES: prior logistics closure; this issue diagnosis
CURRENT_PHASE: KOL UAT remediation
CURRENT_WORK_PACKAGE: ERP-KOL-ORDER-UAT-REMEDIATION-20261008
PACKAGE_STATUS: AUTHORIZED_BACKEND; NUMBERING_UI_DESIGN_PENDING_OWNER_PASS
WAITING_PHASES: numbering visual PASS, integrated regression, Owner UAT, release approval
BASELINE: origin/main 4635aee
GITHUB_STATE: dedicated branch, one draft PR to be created
SOURCE_CONFLICTS: AI/CURRENT_CONTEXT is dated 2026-08-29; document 13 V3 overlay and latest GitHub supersede its historical phase labels. Original handoff #426 is superseded by supplied #428 closure. Manual KOL input cannot satisfy frozen identity without a canonical KOL link.
GLOBAL_GATE: Owner visual/UI UAT and Production gates retained
PRODUCTION_DEPLOYMENT_STATUS: NO — this package has no production execution
NEXT_DEPENDENCY: visual approval of bounded KOL selection, not a new numbering scheme
EVIDENCE_AS_OF: 2026-10-08

## ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: planning 2.5 effective hours; range 1.5–4.0
KNOWN_CURRENT_TRACK_REMAINING: this bounded package only; 1.5–4.0 effective hours
ARCHITECTURE_GATE_REMAINING: complete baseline per document 13
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE — document 13 contains historical cumulative planning, not a reconciled current total
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE — no accepted ledger reconciled for this task
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE — not recomputed from historical horizons
MVP1_REMAINING: UNKNOWN WITH EVIDENCE — no overlap-adjusted remaining ledger
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE — outside this bounded repair
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE — no independent cumulative horizons summed
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE — no accepted actuals/overlap map
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE — no accepted work-log ledger
ESTIMATE_CONFIDENCE: medium for bounded code; low for external UAT/access
ESTIMATE_AS_OF: 2026-10-08
ESTIMATE_METHOD: task decomposition; no wall-clock-as-effort assumption

## ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: planning 0.5; range 0.3–0.7
CORE_EXECUTION: planning 1.0; range 0.6–1.5
TEST_AND_VALIDATION: planning 0.75; range 0.4–1.2
REMEDIATION_BUFFER: planning 0.25; range 0.2–0.6
ESTIMATED_EFFECTIVE_HOURS: 2.5
ESTIMATE_RANGE: 1.5–4.0
ESTIMATE_BASIS: owner isolation plus canonical KOL-number integration; no schema redesign
OWNER_REVIEW: EXCLUDED
MERGE: INCLUDED
MIGRATION: EXCLUDED
UAT: INCLUDED
DEPLOYMENT: EXCLUDED
