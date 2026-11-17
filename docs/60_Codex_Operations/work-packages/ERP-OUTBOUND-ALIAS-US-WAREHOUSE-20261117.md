# ERP-OUTBOUND-ALIAS-US-WAREHOUSE-20261117

EXECUTION_PROVIDER: GPT_WEB

## Authorized scope
Owner 2026-11-17 approved relaxing outbound ERP SKU alias checks while retaining genuine warehouse identity, stock, duplicate-dispatch, and physical shipment safeguards.
- `007-1108-c42194A` (A child only) target MorePlus / US-LA; preserve parent and sibling B.
- `007-1113-c42380` target US-SM / 美国世迈; correct its legacy FACTORY shipment and carrier together.
- Registered active unique `BY489A-Lubes` identifies Product `BY489A`; export/send canonical SKU without requiring the source alias to be independently OMS verified.
- Keep distinct physical OMS catalog verification, availability, dispatch review, genuine tracking, WMS64 manual spreadsheet import, and no duplicate provider drafts.

## Exclusions and constraints
No blanket disabling of SKU/product ID validation; no Woo write, no OMS creation, no shipment confirmation, no inventory fabrication, no sibling B modifications. Real order rerouting and formal Production deployment require the applicable Owner authorization and live readback. Changes to orders must use audited domain services, not direct SQL rewrites.

## Implementation and regression
- Add outbound-only canonical SKU resolver; keep inbound OMS identity strict.
- Apply resolver at MorePlus canary/payload/stock materialization and MorePlus + US-SM XLSX file boundaries.
- Recompute physical shipment carrier/service and the single-leg allocation snapshot when reassignment repairs mismatched route and shipment.
- Route HTTP contract positive/negative tests, split-order reactivation/reallocation regressions, MorePlus and WMS64 export tests, and anti-duplicate/provider-gate tests.
- US Mac 2026-11-17: 174 targeted/neighbor regression tests, 2580 assertions PASS; `npm run build` and independent workspace-tabs verifier PASS. Repeat at exact PR head after final edits. No Dev visual PASS claimed.

## Acceptance
1. A aliases to canonical MorePlus physical SKU only when the verified provider inventory has available stock; no new OMS draft without approved human flow.
2. Non-split order with existing route US-SM but shipment FACTORY changes pending physical shipment to US-SM and recomputes USPS; tracking remains empty until genuine.
3. A/B siblings keep separate identities; completed/started logistics cannot be silently reset; manual US-SM draft still requires operator duplicate check and genuine USPS label.
4. Production release gate, exact SHA, rollback and post-release order DB/readback are separate and must be documented.

PROJECT POSITION
PROGRAM_ROADMAP: Architecture and MVP1 foundations [complete] → GitHub/Production reconciliation [current strategic] → stability/historical audit → warehouse bills → inventory/fulfillment hardening → accounting completeness → future MVP2-4 and content expansion
CURRENT_TRACK_PHASE_MAP: Production investigation [complete] → alias/physical-route patch [current] → PR review/merge [waiting] → formal release approval [waiting] → real order route/readback [waiting] → Dede/manual warehouse fulfillment [waiting]
COMPLETED_PHASES: Read-only Production evidence, user warehouse choices, targeted test/build
CURRENT_PHASE: PR delivery and broader quality validation
CURRENT_WORK_PACKAGE: ERP-OUTBOUND-ALIAS-US-WAREHOUSE-20261117
PACKAGE_STATUS: IMPLEMENTED; CI/PR PENDING
WAITING_PHASES: PR merge; formal Production gate; live order routing
BASELINE: origin/main 5c5d9a8f3d72a692cdec99e5d5922b8a1e332439 (PR #797)
GITHUB_STATE: Uncommitted branch fix/warehouse-alias-outbound-c42194-c42380-20261117 as of 2026-11-17
SOURCE_CONFLICTS: NONE evidenced by latest origin/main fetch at baseline
GLOBAL_GATE: Order mutation HTTP regression and workspace-tabs preservation required
PRODUCTION_DEPLOYMENT_STATUS: NO; no formal release performed for this package
NEXT_DEPENDENCY: Green PR checks and production Owner approval
EVIDENCE_AS_OF: 2026-11-17 Asia/Hong_Kong

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 2–4 effective engineering hours
KNOWN_CURRENT_TRACK_REMAINING: PR/release/real-order acceptance 1–3 hours beyond passed tests
ARCHITECTURE_GATE_REMAINING: UNKNOWN; repository architecture gate baseline unrelated to this bounded hotfix
MVP1_TOTAL_ESTIMATE: UNKNOWN; old planning document not a valid current actual-hours ledger
MVP1_PLANNING_BASELINE: Historical program roadmap docs/10_Planning_Roadmap/13_PROGRAM_ROADMAP_AND_HOURS.md
MVP1_MAXIMUM_ENVELOPE: UNKNOWN; not restated by this hotfix
MVP1_REMAINING: UNKNOWN; historical MVP1 complete
POST_MVP_KNOWN_INCREMENT: This bounded remediation only, 2–4 hours
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN; no new portfolio-wide forecast
PROGRAM_REMAINING: UNKNOWN; not computed from single hotfix
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN; no accepted work log in this package
ESTIMATE_CONFIDENCE: Medium
ESTIMATE_AS_OF: 2026-11-17
ESTIMATE_METHOD: Bounded diffs, regression suites and release/readback checklist

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.5h
CORE_EXECUTION: 1.0–1.5h
TEST_AND_VALIDATION: 0.5–1.0h
REMEDIATION_BUFFER: 0.5–1.0h
ESTIMATED_EFFECTIVE_HOURS: 2–4h
ESTIMATE_RANGE: 2–4h
ESTIMATE_BASIS: Existing canonical SKU and audited routing services, no schema migrations
OWNER_REVIEW: INCLUDED
MERGE: INCLUDED
MIGRATION: EXCLUDED
UAT: INCLUDED
DEPLOYMENT: EXCLUDED
