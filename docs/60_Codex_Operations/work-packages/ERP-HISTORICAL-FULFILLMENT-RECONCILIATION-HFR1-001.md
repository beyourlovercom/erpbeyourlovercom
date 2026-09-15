# ERP-HISTORICAL-FULFILLMENT-RECONCILIATION-HFR1-001

STATUS: ACTIVE / FORMAL CONTRACT FROZEN / DRY-RUN ONLY
EXECUTION_PROVIDER: GPT_WEB
VERIFICATION_TIER: HIGH_RISK
OWNER: Harr
SYSTEM: BYL ERP / Logistics OS
BASELINE: origin/main@ddf4d48d2d89633ec53a3169e9678d62be7cb530
BRANCH: feature/erp-historical-fulfillment-reconciliation-hfr1-001
WORKTREE: /Users/a/AI/007/worktrees/erp-historical-fulfillment-reconciliation-hfr1-001
DEPENDENCIES: HFR-0 PR #317 merged; HFR-0 frozen Production matrix is evidence authority. PR #313 Warehouse Master canonicalization is merged in baseline.
DEPENDENCY_TYPE: MERGED_PR
DEPENDENCY_STATUS: SATISFIED
PARALLEL_SAFE: YES — new Logistics reconciliation classes/tests/docs only.

## Purpose
Implement the reusable, provider-neutral Historical Fulfillment Reconciliation classifier required by the canonical HFR handoff. HFR-1 is pure/read-only: it consumes normalized exact evidence and canonical-state observations and returns deterministic classification, reason codes, decision and proposed Shipment facts. It performs no database/provider mutation.

## Frozen output vocabulary
`CUSTOMER_READY_BACKFILL`, `ALREADY_CANONICAL`, `HISTORICAL_NON_CUSTOMER`, `CUSTOMER_REVIEW_MISSING_EVIDENCE`, `CUSTOMER_REVIEW_CONFLICT`, `ORPHAN_EVIDENCE`, `DUPLICATE_OR_AMBIGUOUS`.
Decision vocabulary is exactly `ready`, `review`, `skip`.
No numeric/fuzzy confidence score is allowed.
## Canonical normalized input contract
Each candidate supplies: candidate key; evidence source/provenance; optional canonical Order identity; exact identity cardinality; optional existing Shipment; ShipmentAttempt observations; exact tracking; exact/approved warehouse; exact/approved carrier/service; source-backed shipped_at; duplicate/ambiguity/collision flags; and historical-non-customer marker.
Provider-specific parsing is explicitly out of HFR-1 and belongs to HFR-2 adapters.

## Classification precedence
1. explicit historical/non-customer -> `HISTORICAL_NON_CUSTOMER` / skip;
2. multiple possible Order/Shipment mappings or duplicate tracking -> `DUPLICATE_OR_AMBIGUOUS` / review;
3. no canonical Order -> `ORPHAN_EVIDENCE` / review;
4. non-customer canonical Order -> `HISTORICAL_NON_CUSTOMER` / skip;
5. identity/status/tracking/ShipmentAttempt/existing-Shipment contradiction -> `CUSTOMER_REVIEW_CONFLICT` / review;
6. existing canonical Shipment identical to exact evidence -> `ALREADY_CANONICAL` / skip;
7. any required exact Shipment fact missing -> `CUSTOMER_REVIEW_MISSING_EVIDENCE` / review;
8. otherwise -> `CUSTOMER_READY_BACKFILL` / ready.

## Required READY facts
Exact canonical customer Order identity; compatible shipped lifecycle; exact tracking; exact/approved warehouse; exact/approved carrier and service; source-backed shipped_at; no existing non-identical Shipment; no tracking collision; no contradictory ShipmentAttempt; no ambiguity.

## Prohibitions
No Production write, schema, migration, UI, Woo sync, Inventory mutation, Finance/P6 mutation, fuzzy inference, current-routing-as-historical-proof, or provider-specific parsing in the core classifier.
## Acceptance criteria
- Every frozen HFR class is reachable through deterministic rules.
- Core output includes candidate key, exact order identity when present, evidence source, class, reason codes, decision and proposed Shipment facts.
- Missing evidence remains REVIEW; contradictions remain CONFLICT; duplicates/ambiguity fail closed.
- Existing Shipment is ALREADY_CANONICAL only when authoritative supplied evidence agrees; unapproved hints cannot create false conflicts.
- No provider parsing, database writes, network calls, schema/UI changes, numeric confidence score or mutation path exists in the classifier.
- Focused unit tests, historical Logistics regression, Pint and `git diff --check` pass on latest main.

## Validation evidence
- Classifier: 12 tests / 24 assertions PASS.
- Historical Logistics regression: CustomerHistoricalShipmentBackfill + HistoricalImport = 13 tests / 128 assertions, no failures; inherited fixture/file-read warnings only.
- Production frontend build generated the local test Vite manifest; no tracked frontend file was changed.
- Pint: PASS for both changed PHP files.
- `git diff --check`: PASS.
- Production mutation: NONE; this WP did not access Production for writes.

PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate -> MVP1 Existing Operations / Tencent Docs Replacement -> ERP Inquiry -> Remote Milestone -> Woo Inbound -> Tracking Outbound -> Sync Orchestration -> Product/Media -> WordPress Product/Image Sync -> Content/SEO -> Posts/Pages -> GA4/GSC/Cloudflare -> Google Docs Publishing -> Full Order Workspace -> Logistics OS -> KOL OS/Social Providers -> Inventory/Procurement -> Accounting/ROI -> Additional Integrations -> Production Hardening/Expansion
CURRENT_TRACK_PHASE_MAP: HFR-0 Production inventory [COMPLETE] -> HFR-1 Reconciliation classifier [CURRENT] -> HFR-2 Source adapters [WAITING] -> HFR-3 Customer Shipment Backfill V2 [WAITING DATA WRITE GATE] -> HFR-4 Historical/KOL isolation [WAITING] -> HFR-5 Review workbench [DEFERRED] -> HFR-6 Continuous reconciliation [OPTIONAL]
COMPLETED_PHASES: HFR-0 / PR #317 merged
CURRENT_PHASE: HFR-1 classifier implementation and delivery
CURRENT_WORK_PACKAGE: ERP-HISTORICAL-FULFILLMENT-RECONCILIATION-HFR1-001
PACKAGE_STATUS: IMPLEMENTED / VERIFIED / DELIVERY PENDING
WAITING_PHASES: Git delivery; HFR-2 Woo-first source adapter; HFR-3 guarded backfill engine; first HFR Production Data Write Gate
BASELINE: origin/main@ddf4d48d2d89633ec53a3169e9678d62be7cb530
GITHUB_STATE: dedicated HFR-1 branch/worktree on exact latest main; PR pending
SOURCE_CONFLICTS: NONE WITH EVIDENCE for HFR-1; HFR-0 matrix is merged repository truth and current main changes do not overlap classifier ownership
GLOBAL_GATE: DRY-RUN ONLY + EXACT MATCH ONLY + NO PRODUCTION/FINANCE/INVENTORY MUTATION
PRODUCTION_DEPLOYMENT_STATUS: NO HFR-1 Production deployment or mutation
NEXT_DEPENDENCY: deliver HFR-1, refresh latest main, implement HFR-2 Woo historical metadata adapter
EVIDENCE_AS_OF: 2026-09-15 +08:00

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 2.0 effective hours planning value; range 1.2-3.2h
KNOWN_CURRENT_TRACK_REMAINING: HFR-2/HFR-3 separately bounded; not summed here
ARCHITECTURE_GATE_REMAINING: 0 for frozen HFR-1 classifier contract
MVP1_TOTAL_ESTIMATE: historical program estimate not recalculated by this package
MVP1_PLANNING_BASELINE: historical program baseline not recalculated by this package
MVP1_MAXIMUM_ENVELOPE: historical program envelope not recalculated by this package
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; accepted project-wide actual ledger is incomplete
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE for HFR track
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE; not recomputed here
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE; parallel tracks prevent safe arithmetic
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; no accepted Work Log ledger exists
ESTIMATE_CONFIDENCE: MEDIUM
ESTIMATE_AS_OF: 2026-09-15
ESTIMATE_METHOD: one provider-neutral pure classifier + focused contract tests + historical Logistics regression

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.3h planning value; range 0.2-0.5h
CORE_EXECUTION: 0.9h planning value; range 0.5-1.5h
TEST_AND_VALIDATION: 0.5h planning value; range 0.3-0.8h
REMEDIATION_BUFFER: 0.3h planning value; range 0.2-0.4h
ESTIMATED_EFFECTIVE_HOURS: 2.0h
ESTIMATE_RANGE: 1.2-3.2h
ESTIMATE_BASIS: pure deterministic classifier, normalized contract, reason-code tests and bounded Logistics regression
OWNER_REVIEW: EXCLUDED
MERGE: INCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
