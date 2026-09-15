# ERP-HISTORICAL-FULFILLMENT-RECONCILIATION-HFR0-001

STATUS: ACTIVE / FORMAL CONTRACT FROZEN / PRODUCTION READ-ONLY
EXECUTION_PROVIDER: GPT_WEB
VERIFICATION_TIER: HIGH_RISK
OWNER: Harr
SYSTEM: BYL ERP / Logistics OS
BASELINE: origin/main@3eed38479d7f3338e5b0f707c8c9315106c33239
BRANCH: feature/erp-historical-fulfillment-reconciliation-hfr0-001
WORKTREE: /Users/a/ai/007/worktrees/erp-historical-fulfillment-reconciliation-hfr0-001
DEPENDENCIES: ERP-LOGISTICS-CUSTOMER-HISTORICAL-SHIPMENT-BACKFILL-001 is a completed reference sample only; Accounting P6 is consumer-only and not a dependency.
DEPENDENCY_TYPE: MERGED_PR
DEPENDENCY_STATUS: SATISFIED
PARALLEL_SAFE: YES — additive backend/docs only; Production access is SELECT/read-only.

## Purpose
Establish the exact Production Historical Fulfillment Gap Matrix before any new HFR data write. Inventory canonical customer Orders, Shipments, ShipmentAttempts, historical logistics evidence, exact WooCommerce identity/tracking evidence, duplicate/orphan tracking and warehouse/carrier coverage. No source is promoted to canonical Shipment truth during HFR-0.

## Frozen truth and classification
Canonical hierarchy remains `orders -> shipments -> billing/delivery -> Finance`. Historical/provider evidence may support reconciliation but is not canonical truth by itself.
Candidate vocabulary is frozen to: CUSTOMER_READY_BACKFILL, ALREADY_CANONICAL, HISTORICAL_NON_CUSTOMER, CUSTOMER_REVIEW_MISSING_EVIDENCE, CUSTOMER_REVIEW_CONFLICT, ORPHAN_EVIDENCE, DUPLICATE_OR_AMBIGUOUS.
All matching is exact-only. No fuzzy name/date/address/SKU/customer inference is allowed.

## Scope
- Read Production counts by order kind/status and Shipment coverage.
- Identify `customer + shipped` Orders with no canonical Shipment.
- Measure ShipmentAttempt and exact tracking coverage for that gap.
- Measure historical import/materialization/rescue evidence without treating KOL evidence as customer evidence.
- Measure exact WooCommerce order-reference coverage and read remote tracking metadata only when available through the existing read gateway.
- Measure duplicate tracking, orphan tracking and known warehouse/carrier source coverage.
- Produce exact source-by-source yield and recommend HFR-1 implementation target.
- Add only bounded reusable read-only inventory/reporting code if needed; no schema and no UI.

## Explicit prohibitions
- No Production INSERT/UPDATE/DELETE/DDL or migration execution.
- No Shipment/ShipmentAttempt creation or mutation.
- No Order status mutation.
- No Woo write/sync/manual sync.
- No carrier billing or Finance/FX/Order Profit mutation.
- Do not rerun the Tianyun 27-row Shipment backfill.
- Do not promote the 16 Tianyun KOL/historical rows.
- No fuzzy matching or carrier-bill-as-order-identity inference.
- No scheduler activation and no ERP changelog update.

## Acceptance criteria
- Exact Production count of current customer+shipped Orders with and without canonical Shipment.
- Exact ShipmentAttempt/tracking coverage for the missing-Shipment population.
- Exact historical evidence counts and proof of customer/KOL separation.
- Exact source-by-source tracking coverage, duplicate and orphan counts.
- Historical Fulfillment Gap Matrix recorded with evidence timestamp and Production release observation.
- Every candidate pool can be mapped into the frozen HFR classes without invented facts; unresolved fields remain REVIEW.
- HFR-1 is proposed from the highest-yield exact evidence source.
- `git diff --check` and focused tests for any reusable reporting code pass.
- Production remains read-only for the entire package.

## Initial Production discovery evidence
Read-only observations on 2026-09-15 +08:00:
- customer+shipped Orders = 5,110;
- with canonical Shipment = 28;
- without canonical Shipment = 5,082;
- missing-Shipment Orders with ShipmentAttempt = 0;
- all 5,082 gaps are WooCommerce source and have an exact `order_external_references` row;
- historical-only ShipmentAttempts = 1,009 across 886 KOL fulfillment Orders and must not be reused as customer proof;
- canonical Shipments total = 43 at first inventory query; known historical_exact Tianyun sample remains separately frozen.
These are discovery facts pending the complete source matrix and drift-safe recheck before closure.

PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate -> MVP1 Existing Operations / Tencent Docs Replacement -> ERP Inquiry -> Remote Milestone -> Woo Inbound -> Tracking Outbound -> Sync Orchestration -> Product/Media -> WordPress Product/Image Sync -> Content/SEO -> Posts/Pages -> GA4/GSC/Cloudflare -> Google Docs Publishing -> Full Order Workspace -> Logistics OS -> KOL OS/Social Providers -> Inventory/Procurement -> Accounting/ROI -> Additional Integrations -> Production Hardening/Expansion
CURRENT_TRACK_PHASE_MAP: HFR-0 Production inventory [CURRENT] -> HFR-1 Reconciliation classifier [WAITING] -> HFR-2 Source adapters [WAITING] -> HFR-3 Customer Shipment Backfill V2 [WAITING DATA WRITE GATE] -> HFR-4 Historical/KOL isolation [WAITING] -> HFR-5 Review workbench [DEFERRED] -> HFR-6 Continuous reconciliation [OPTIONAL]
COMPLETED_PHASES: Tianyun 27-row customer historical Shipment sample only; HFR project foundation/handoff
CURRENT_PHASE: HFR-0 Production Historical Fulfillment Inventory
CURRENT_WORK_PACKAGE: ERP-HISTORICAL-FULFILLMENT-RECONCILIATION-HFR0-001
PACKAGE_STATUS: ACTIVE / PRODUCTION READ-ONLY
WAITING_PHASES: complete source matrix; HFR-1 classifier; later Production Data Write Gate
BASELINE: origin/main@3eed38479d7f3338e5b0f707c8c9315106c33239
GITHUB_STATE: dedicated branch/worktree rebased after PR #312 to exact latest origin/main; delivery PR not yet opened
SOURCE_CONFLICTS: AI/CURRENT_CONTEXT.md is historically stale for current Logistics/Accounting mutable state; GitHub main + this Owner-approved HFR handoff + direct read-only Production evidence govern this package.
GLOBAL_GATE: EXACT MATCH ONLY + PRODUCTION READ-ONLY + ONE_WORK_PACKAGE_ONE_BRANCH_ONE_PR + ONE_ACTIVE_IMPLEMENTATION_PROVIDER_PER_WORKTREE
PRODUCTION_DEPLOYMENT_STATUS: Existing Production is live; this package performs no Production deployment or mutation.
NEXT_DEPENDENCY: complete exact HFR-0 source coverage, then issue HFR-1 from latest merged main
EVIDENCE_AS_OF: 2026-09-15 +08:00

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 2.5 effective hours planning value; range 1.5-4.0h
KNOWN_CURRENT_TRACK_REMAINING: HFR-0 2.5h planning value only; later packages are separately bounded and not summed here
ARCHITECTURE_GATE_REMAINING: 0 for HFR-0; no schema/UI architecture is authorized
MVP1_TOTAL_ESTIMATE: historical program estimate not recalculated by this package
MVP1_PLANNING_BASELINE: historical program baseline not recalculated by this package
MVP1_MAXIMUM_ENVELOPE: historical program envelope not recalculated by this package
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; HFR is a later reconciliation track and accepted total actual ledger is incomplete
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE for this bounded HFR package
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE; not recomputed here
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE; parallel Logistics/Accounting tracks prevent safe arithmetic
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE until package closure
ESTIMATE_CONFIDENCE: MEDIUM
ESTIMATE_AS_OF: 2026-09-15
ESTIMATE_METHOD: bounded Production SELECT inventory + provider metadata read + gap matrix + focused reusable reporting/test work

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.4h planning value; range 0.2-0.6h
CORE_EXECUTION: 1.2h planning value; range 0.7-2.0h
TEST_AND_VALIDATION: 0.6h planning value; range 0.4-0.9h
REMEDIATION_BUFFER: 0.3h planning value; range 0.2-0.5h
ESTIMATED_EFFECTIVE_HOURS: 2.5h
ESTIMATE_RANGE: 1.5-4.0h
ESTIMATE_BASIS: exact Production inventory, source coverage and reusable read-only reporting only; no schema/UI/data writes.
OWNER_REVIEW: EXCLUDED
MERGE: INCLUDED
MIGRATION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
