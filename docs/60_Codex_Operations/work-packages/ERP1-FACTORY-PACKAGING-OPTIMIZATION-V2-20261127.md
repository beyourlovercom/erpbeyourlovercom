# ERP1 Factory Packaging Optimization V2 — B0–B2
Owner-authorized 2026-11-27; ERP1 only; candidate, no production release.
WORK_PACKAGE_ID: ERP1-FACTORY-PACKAGING-OPTIMIZATION-V2-20261127
EXECUTION_PROVIDER: GPT_WEB (bounded pure backend and audit only)
VERIFICATION_TIER: DAILY
BRANCH: feat/erp1-factory-packaging-optimization-v2
WORKTREE: /Users/a/AI/007/_worktrees/erp1-factory-packaging-optimization-v2-20261127
AGENT_SESSION: GPT Web via BYL formal OAuth DC, US Mac, local account a
BASELINE: 6c9347ffc029e53565dd80640dde6568f9886ac7
EXPECTED_FILE_SCOPE: new Inventory/Application/PackagingOptimization* classes, new isolated unit, comparison and reader integration tests, this work package. Existing controllers, product master, logistics, shared grid, ERP2 unchanged.
DEPENDENCIES: A4 approved same-baseline quote contract for B3 onward; V4 R5.1/R5.2 shared grid and rendered design Owner PASS for visual implementation.
DEPENDENCY_TYPE: EXTERNAL (future stages)
DEPENDENCY_STATUS: B0–B2 backend independent; A worktree verified at baseline; A4 evidence unavailable. No stacked dependency.
PARALLEL_SAFE: YES for declared new files only; no shared-file mutation.
FOUNDATION_CHANGE_REQUIRED: NO
## B0 observed source audit
- Existing /inventory/packaging list/create/edit/show/product guide and media routes; submit permission, review/adopt permission, controller own-draft and read isolation. Factory landing regression already exists.
- Existing measurement records: nullable packaged_weight_kg decimal(10,3), dimensions cm decimal(10,2), product/warehouse, statuses draft/submitted/approved/rejected, actor/times/note; media table kinds packaging/weight/measurement. Submit requires weight + measurement evidence.
- Existing product_packaging_profiles links current_measurement_record_id, preset/custom, active flag, version, units_per_carton; canonical ProductPackagingStandardReader joins active profile and approved same-product record. ProductShippingWeightStandardReader resolves adopted aggregate and legacy shipping reference. Do not mutate these shared readers in B0–B2.
- Four presets: direct_mailer, carton, document_bubble_mailer, bubble_wrap_cut. Keep custom history. Product weight evidence has product_weight and shipping_reference_weight; product_weight alone is NOT demonstrated bare weight. Do not automatically inflate it. Confirm explicit bare evidence before real fallback.
- Existing FulfillmentPlanCostEstimator consumes both readers; owned by A. Existing FactoryDemandForecast uses 90-day paid Woo customer parent orders; not equivalent to actual fulfilled route/parcel history. B4 will use actual fulfilled samples.
- Existing list is native table; do not change until visual proposal PASS and shared grid interface confirmed.
- Gaps for B5: rejected record is not editable under own-draft guard; review/adoption concurrency and old-profile method mismatch need focused verification. No changes here.
- PR #887 logistics/SKU and #888 factory import file diffs checked; #859 inventory Products page; no overlap with new files. A worktree /Users/a/ai/007/_worktrees/erp1-logistics-rate-center-v1-20261127 at baseline during inspection. ERP1 public route returned HTTP 302 (authentication redirect); not live authenticated runtime/DB evidence. Production schema/data and deployed SHA unverified. No production writes.
## B1/B2 backend acceptance
Pure read-only input projection of adopted packaged measurement, shipping reference, or explicitly confirmed bare weight; no double increment, missing remains null. Do not mix dimensions from different evidence. Reuse reader classes; no new tables. Geometry only when applicable max(actual,volume/divisor) and explicit divisor; pure actual/unknown rules do not imply savings. Return theoretical volume/height, conservative 0.1 cm target, feasibility status and no price. Minimum safe height if explicitly supplied; otherwise feasibility unverified. No writes, no provider calls, no currency/fee calculation. Test handoff sample, actual dominates, missing inputs, unsafe target, precision, source priority. Unit tests first; full isolated Laravel permission/reader/DB regression and visual approval pending. B3–B7 gated by A4, B6 Owner UAT, B7 separate production approval. No merge in this phase.
## B1–B2 backend candidate evidence (2026-11-27, Beijing 17:26)
- Added only two new Inventory Application classes; existing canonical readers reused without changing them. No migration, route, UI, finance, logistics or ERP2 file modified.
- PackagingOptimizationInputReader: adopted measurement first, then adopted shipping reference; preserves evidence; no mixed dimensions, no repeat 20% increment. Explicit bare-weight fallback is tested as a pure contract only; real product_weight remains unclassified until explicit provenance is confirmed.
- PackagingOptimizationGeometry: explicit rule/divisor, no fee implementation. Sample 18597.15 cm³, 3.099525 kg, target 13392 cm³, conservative candidate H=13.4 cm. Candidate is not product safety approval. Missing data, pure actual rule, unknown rule, no excess and fixed-base infeasible case covered.
- Fresh independent Composer and npm dependencies (no shared vendor/node_modules mutation); no .env, .env.testing or cached config. Test environment explicitly SQLite :memory:, array mail/session/cache, no provider credentials.
- PHP syntax checks PASS. Initial old worktree PHPUnit path absent; resolved by independent Composer install. First combined regression 43 tests/146 assertions, 6 failures with missing Vite manifest; rebuilt actual assets and reran, not waived.
- Final focused regression: PackagingMeasurementEvidenceTest + ShippingWeightStandardCandidateTest + PackagingOptimizationInputReaderTest + PackagingOptimizationTest: 45 tests / 224 assertions PASS, including 8 new tests / 45 assertions and database readback. Typecheck PASS. npm run build PASS; existing workspace-tabs bundle check PASS. Build emitted chunk-size/plugin timing warnings, no error.
- B0 code audit and isolated schema/readback complete at candidate scope; authenticated production page/current deployed SHA, real production evidence coverage and live role configuration still unverified.
- B1/B2 backend candidate validated; UI not implemented because Owner rendered-design approval is mandatory; ERP V4 shared grid dependency not yet confirmed. B3–B7 remain gated by A4.
- A worktree at 6c9347ff; no PR for its exact branch at the last read; no A4 contract delivery evidence. No changes in A's worktree.
- Executed via BYL formal OAuth on US Mac, OS account a, GitHub actor beyourlovercom. No production or ERP2 mutation.
- Actual effective hours: UNKNOWN WITH EVIDENCE; no accepted work-log ledger. No merge-readiness claim.
## B0–B2 continuation evidence (2026-11-27, Beijing 17:30)
- Previous exact candidate 0fb28497: GitHub four checks SUCCESS (SQLite, MySQL 8, workspace tabs, SKU isolation). Merged current main f698a49c (A0–A1 PR #903) into the same isolated delivery branch; no conflict. A4 remains unverified. A's foundation is not an A4 pricing API.
- B2 added PackagingOptimizationComparison: four canonical preset keys read from PackagingPresetCatalog; independently supplied single-parcel candidate measurements compared against current baseline. Missing measurements remain null; negative weight differences are retained; unrecognized method keys reported; no price or safety/adoption approval. No new routes, UI, DB schema or shared rate engine. Custom historical records remain untouched.
- Focused PHP test run (isolated SQLite, including A0–A1 foundation regression): 53 tests / 273 assertions PASS. Two new comparison tests include missing candidate weight, unknown rule, actual-only, missing baseline and negative comparison.
- Production active symlink readback: releases/erp1-us-ny-xlwms-pr901-6c9347ff-20261127. This is a release-directory identifier, not independent exact commit attestation. Executed aggregate-only MySQL START TRANSACTION READ ONLY / ROLLBACK via canonical runtime on US Mac. No mutation or PII retrieved.
- Actual data: 177 active products; 1 active product with complete adopted packaging standard; 2 approved measurement records (both document_bubble_mailer); 2 weight + 2 dimension photos; 47 active products with canonical adopted shipping reference (49 adopted shipping evidence rows); 2 adopted product_weight evidence rows (bare weight not confirmed).
- B4 prep: 225 shipments marked shipped with shipped_at in trailing 90 days; 31 of those have at least one shipment-item packaging snapshot. Separately 139 snapshots captured in trailing 90 days, across 139 shipments, not the same cohort. Do not use snapshot creation time as shipment date or equate snapshots with distinct parcels. Existing shipment_item_packaging_snapshots unique shipment_id+order_item_id, no parcel quantity/group identity in that table. B4 route/sample and multi-parcel attribution remain pending; missing evidence excluded from price ranking.
- Live role-permission readback: administrator and uat_owner_admin roles have submit+review; uat_factory_packaging_operator role has submit only. Actual employee assignments/effective permissions and authenticated browser UAT not established. Existing review/submit tests remain green. No role changes.
- ERP V4 public Unified Grid V2 contract/Owner sample PASS not evidenced in current main; existing GlobalGrid files alone do not establish that gate. Owner visual proposal not explicitly accepted by the continuation request. No UI files changed.
- Actual effective hours remain unknown (no accepted ledger); no merge or release readiness claim.
## PROJECT POSITION
PROGRAM_ROADMAP: see exact canonical sequence below (document 13; historical status superseded by current V3 overlay).
## Program Roadmap

```text
Architecture Migration / Global Architecture Gate [COMPLETE]
→ ERP-INQUIRY-M1 M1.0 Contract + M1.1 Company [COMPLETE]
→ ERP-ORDER-FULFILLMENT-M1 [COMPLETE]
→ Product Master / SKU M1 [COMPLETE]
→ Order Create/Edit V2 [COMPLETE]
→ Inventory Foundation + Inventory Batch Sync M1 [COMPLETE]
→ MVP1 Manual Fulfillment Integration [COMPLETE / PR #101]
→ MVP1.1 WooCommerce Inbound [COMPLETE / PR #104]
→ MVP1.2 Tracking Outbound [COMPLETE / PR #106]
→ MVP1.3 Sync Orchestration [COMPLETE / PR #108]
→ KOL M1.0 Foundation [COMPLETE / PR #103]
→ KOL M1.1 CRM [COMPLETE / PR #110]
→ ROADMAP RECONCILIATION [CURRENT / DOCS MERGE GATE]
→ KOL M1.2A Collaboration [NEXT]
→ KOL M1.2B Canonical Fulfillment Bridge
→ KOL M1.3 Content
→ KOL M1.4 Finance / Affiliate
→ KOL V1 Cross-phase Closure / Full Regression / Owner UAT
→ MVP2 Inventory Reservation Foundation + MVP2.1–MVP4 Fulfillment Intelligence / Connectors / Automation [PAUSED UNTIL KOL V1 CLOSED]
→ Accounting / Procurement expansion
→ remaining Inquiry milestone when reactivated
→ Milestone Remote Release / erp.beyourlover.com
→ Product / Media / Content / SEO / Shopify expansion
→ Production Hardening
```

The Program Roadmap is the top-level delivery sequence. It must not be replaced by the current Architecture track or a single Work Package map.
CURRENT_TRACK_PHASE_MAP: B0 audit → B1 data contract → B2 geometry → B3 A4 comparison → B4 ranking → B5 workflow → B6 UAT → B7 release
COMPLETED_PHASES: handoff read; source/PR preflight; production aggregate read-only audit; B1/B2 backend candidate, four-preset comparison, isolated focused tests
CURRENT_PHASE: B0–B2 candidate; UI and actual employee browser evidence open
CURRENT_WORK_PACKAGE: ERP1-FACTORY-PACKAGING-OPTIMIZATION-V2-20261127
PACKAGE_STATUS: OPEN / BACKEND_CANDIDATE_TESTED / DESIGN_PENDING_OWNER_PASS
WAITING_PHASES: authenticated employee browser evidence; UI visual PASS; V4 shared grid; B3–B7; A4 contract
BASELINE: 6c9347ffc029e53565dd80640dde6568f9886ac7
GITHUB_STATE: main f698a49c merged into candidate branch; Draft PR #902 open; no merge to main
SOURCE_CONFLICTS: earlier ERP2 parity language superseded by ERP1-only directive; stale AI/CURRENT_CONTEXT roadmap superseded by current Owner handoff and document 13 V3 overlay; production release directory identifies 6c9347ff; independent exact SHA attestation unavailable.
GLOBAL_GATE: no release; preserve Owner visual and UAT approval; no real DB writes
PRODUCTION_DEPLOYMENT_STATUS: NO for this package; active production release directory and read-only MySQL aggregate verified
NEXT_DEPENDENCY: A4 contract + shared-grid / design approval
EVIDENCE_AS_OF: 2026-11-27 17:30 Beijing time; OS account a; GitHub actor beyourlovercom
## ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 4h planning / 2.5–6h backend-only scope (estimate, not elapsed or promise)
KNOWN_CURRENT_TRACK_REMAINING: UNKNOWN WITH EVIDENCE; A4 not delivered, B3–B7 unestimated
ARCHITECTURE_GATE_REMAINING: 0h per document 13 historical architecture closure; V4 independent
MVP1_TOTAL_ESTIMATE: 122–190h
MVP1_PLANNING_BASELINE: 166h
MVP1_MAXIMUM_ENVELOPE: 192h
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; incomplete accepted ledger per document 13
POST_MVP_KNOWN_INCREMENT: approximately 68–120h, Woo inbound + outbound historical planning
LONG_TERM_CUMULATIVE_RANGE: alternative horizons 235–402h; 603–1018h; 1059–1802h; 1595–2714h; 1819–3146h per document 13, never sum
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE; incomplete accepted ledger and overlap map
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; no accepted actual-hours ledger for package
ESTIMATE_CONFIDENCE: low, backend only; live schema and UI not audited
ESTIMATE_AS_OF: 2026-11-27
ESTIMATE_METHOD: bounded new pure classes and tests; excludes dependency wait
## ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.5–1h, planning 0.75h
CORE_EXECUTION: 1–2.5h, planning 1.75h
TEST_AND_VALIDATION: 0.5–1.5h, planning 1h
REMEDIATION_BUFFER: 0.5–1h, planning 0.5h
ESTIMATED_EFFECTIVE_HOURS: 4h
ESTIMATE_RANGE: 2.5–6h
ESTIMATE_BASIS: B0 source audit plus B1/B2 bounded backend code; no UI or production
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
