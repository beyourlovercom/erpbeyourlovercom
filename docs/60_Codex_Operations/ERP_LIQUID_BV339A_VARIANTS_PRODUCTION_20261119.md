# ERP Liquid BV339A Variants · Production Follow-up
Date: 2026-11-19
WORK_PACKAGE_ID: ERP-LIQUID-BV339A-VARIANTS-20261119
EXECUTION_PROVIDER: GPT_WEB / self-built BYL DC Remote only
VERIFICATION_TIER: HIGH_RISK / MILESTONE_RELEASE
BRANCH: fix/product-liquid-bv339a-variants-20261119
BASELINE: origin/main 2cbb405c (#806 merged, #808 separately merged but excluded from this Production release)
DEPENDENCY: PR #806, merge aa6bae06819269f6156cf7d48d42712a688865bb
OWNER_SCOPE: Owner confirmed BV339A-P and BV339A-Y both liquid, alongside BY489A and BV339A.
PRODUCTION_AUTHORIZATION: Owner explicitly authorized controlled release after tests in conversation.
STATUS: OWNER_LIVE_UAT_PASS / LOCAL_CI_PASS / FINAL_PRODUCTION_PREFLIGHT_PENDING.

## Root cause / Production preflight
- Initial preflight active release: erp-legacy-route-pr804-e2c07374-20261119. After Owner UAT, the active Production pointer advanced to PR #807; preserve that approved Production release during selective rollout.
- Production Products: BY489A exists once; exact BV339A missing; BV339A-P and BV339A-Y each exist.
- Existing PR #806 migration seeds only exact BY489A/BV339A, leaving actual two variant Products false.
- Product Woo/CSV/manual creation paths also need exact default values; never fuzzy-match an arbitrary BV339A suffix.
- Do not mutate orders, OMS, shipping, tracking, SKU aliases, material names or accounting.
- Product master flags remain manually editable; Woo sync must not overwrite existing values.

## Corrective implementation
- Approved exact default set: BY489A, BV339A, BV339A-P, BV339A-Y.
- Add independent additive data migration for preexisting BV339A-P/Y; do not edit merged PR #806 migration.
- Default new manual, Woo API inbound and Woo CSV Products to exact liquid set; preserve manual override.
- Product Create selection follows SKU initial value; operator may explicitly correct it.
- Pending domestic warnings use existing PR #806 Product metadata projection and remain nonblocking.

## Owner live UAT and CI evidence
- On 2026-11-19 Owner replied `pass` to the fully logged-in US Mac mini local Chrome five-tab UAT at http://127.0.0.1:8778 (Create, BV339A-P/Y Product360, Domestic Pending, Products List).
- Local SQLite migration readback: two variant Product rows false before and true after; browser Create preselected BV339A-P liquid, both Product360 pages showed true; all five HTTP 200.
- Focused Laravel 548 assertions, Vue six tests, Pint, TypeScript, Vite build and 30-tab guard passed. GitHub PR #810 initial head 7fe07238 had all four triggered checks PASS.

## Validation and controlled release contract
- Focused PHP tests: migration backfill + exact/negative SKU defaults + manual override + Woo inbound and CSV import preservation.
- Vue typecheck, frontend build and independent frozen 30-tab bundle check.
- Production release must use PR #806 plus this corrective PR only, not blindly publish main/#808 Product Cost changes.
- Before migration: exact active-release symlink and hash preflight, independent fresh gzip MySQL backup with integrity, candidate source/build parity, rollback pointer.
- Execute both pending liquid migrations once in correct order with `--force`; record migration ledger, exact four flags, no other SKU mutations.
- Atomically switch isolated immutable release, check local origin/public HTTP, authenticated routes, hashed JS, 30-tab bundle and no order/OMS mutation.
- On failure: pointer rollback without rerunning business operations; preserve database backup and do not blindly run destructive down migration.
- The Production Owner gate covers this named scope only; no other PR or broad Production data backfill.

## PROJECT POSITION
PROGRAM_ROADMAP: Architecture -> MVP1 Operations -> Inquiry -> Woo Sync -> Product/Media -> Logistics/KOL/Inventory/Procurement/Accounting -> Hardening
CURRENT_TRACK_PHASE_MAP: PR806 Owner UAT PASS/merged -> BV339 variants correction -> CI -> controlled Production -> readback
COMPLETED_PHASES: PR806 visual/US local UAT PASS, Git merge; Production preflight discrepancy identified
CURRENT_PHASE: Owner UAT PASS / final CI and controlled Production candidate
CURRENT_WORK_PACKAGE: ERP-LIQUID-BV339A-VARIANTS-20261119
PACKAGE_STATUS: OWNER_LIVE_UAT_PASS / PROD_RELEASE_AUTHORIZED_PENDING_FINAL_GATES
WAITING_PHASES: CI, separate correction UAT if necessary, controlled release and readback
BASELINE: main 2cbb405c; Production current PR804 overlay
GITHUB_STATE: PR #810 Draft 7fe07238, CI four checks PASS before docs closure commit
SOURCE_CONFLICTS: Latest main #808 Product Cost is not part of this release; selective overlay required
GLOBAL_GATE: Owner live UAT PASS; exact four liquid SKUs only; no unrelated data write; P0 workspace tabs guard
PRODUCTION_DEPLOYMENT_STATUS: NO WITH EVIDENCE; current still PR804
NEXT_DEPENDENCY: tests and PR merge, then safe production release
EVIDENCE_AS_OF: 2026-11-19

## ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 2-5 effective hours
KNOWN_CURRENT_TRACK_REMAINING: 2-5h correction + controlled deployment
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE
MVP1_REMAINING: UNKNOWN WITH EVIDENCE
POST_MVP_KNOWN_INCREMENT: 2-5h isolated correction
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE
ESTIMATE_CONFIDENCE: medium
ESTIMATE_AS_OF: 2026-11-19
ESTIMATE_METHOD: bounded SKU policy + migration + source tests + candidate release preflight

## ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.5-1h
CORE_EXECUTION: 0.5-1h
TEST_AND_VALIDATION: 0.5-1h
REMEDIATION_BUFFER: 0.5-2h
ESTIMATED_EFFECTIVE_HOURS: 2-5h
ESTIMATE_RANGE: 2-5h
ESTIMATE_BASIS: Exact variant rule correction; Production backed up and gated separately
OWNER_REVIEW: INCLUDED
MERGE: INCLUDED
MIGRATION: INCLUDED
MIGRATION_FILE_AUTHORING: INCLUDED
MIGRATION_EXECUTION: INCLUDED
UAT: INCLUDED
DEPLOYMENT: INCLUDED
