# ERP-PRODUCTION-STABILITY-V1-P0-001

## Scope

Bounded production-stability foundation for request correlation, safe Inertia 5xx handling, request SQL/performance instrumentation, and `/logistics/pending` recommendation batching. No business workflow redesign, schema change, production deployment, production migration, credential change, or external-service change.

## Baseline and invariants

- Baseline after reconciliation: `origin/main` / `e37d5fa4827384d407ea81e14ada8335e11e87f5`.
- Branch: `fix/erp-production-stability-v1-p0-001`.
- One request has one reusable/generated `X-ERP-Request-ID`; exception details remain server-side.
- API JSON and non-500 HTTP semantics remain unchanged.
- Pending-page warehouse and availability reads are request-scoped and batched; row recommendation failure is blocked per row; systemic database failures are not swallowed.
- Production was accessed only through a read-only controller/query benchmark using the live database; no business data, schema, session, credential, or service state was mutated.

## Acceptance gates

1. Correlation/error id response header and structured 5xx context.
2. Production-like Inertia 500 page with concise Chinese text, Error ID, Retry and Back; debug remains useful.
3. Query count/time, duration and peak memory metrics; configurable warning budgets; diagnostic headers opt-in only.
4. Fifty-row logistics recommendations reuse one prepared warehouse/availability context and cache USPS source data per resolver instance.
5. Focused tests cover observability, Inertia/status behavior, budgets and bounded recommendation reads.
6. PHP syntax and focused tests must pass when dependencies are available.

## Rollback notes

Revert this package’s tracked files as one unit. No migration or persistent data change requires rollback. Before any release decision, inspect production logs and run the relevant full verification on an environment with installed dependencies.

## Authorization boundary

Current gate authorizes implementation, verification, branch commit/push, and PR preparation. **NO merge, production deployment, production migration, credential action, or external-service action is authorized by this document.** Production-equivalent benchmarking is read-only only.

PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration → MVP1 Existing Operations → ERP-INQUIRY-M1 → Remote Milestone Release → MVP1.1 WooCommerce Inbound → MVP1.2 Tracking Outbound → MVP1.3 Sync Orchestration → Product/Media → WordPress Sync → Content/SEO → Google Docs → Full Order Workspace → Logistics OS → KOL OS → Inventory/Procurement → Accounting/ROI → Shopify/Integrations → Production Hardening
CURRENT_TRACK_PHASE_MAP: Production stability P0 foundation; no product-track transition
COMPLETED_PHASES: NONE WITH EVIDENCE
CURRENT_PHASE: Production stability hardening
CURRENT_WORK_PACKAGE: ERP-PRODUCTION-STABILITY-V1-P0-001
PACKAGE_STATUS: COMMITTED / PUSHED / VALIDATED / PRODUCTION-EQUIVALENT READ-ONLY BENCHMARK PASS
WAITING_PHASES: PR review / merge gate / separate Production deploy gate
BASELINE: origin/main e37d5fa4827384d407ea81e14ada8335e11e87f5
GITHUB_STATE: branch pushed at 8973c0c2411ecdd9694c2566432e969b3164baa8; PR pending creation
SOURCE_CONFLICTS: NONE WITH EVIDENCE
GLOBAL_GATE: NO merge, Production deployment, Production migration, credential, or external-service action
PRODUCTION_DEPLOYMENT_STATUS: NO WITH EVIDENCE
NEXT_DEPENDENCY: Create PR, inspect CI, then Owner merge gate
EVIDENCE_AS_OF: 2026-09-08

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 4.0 hours planning value; 3–6 hour range
KNOWN_CURRENT_TRACK_REMAINING: UNKNOWN WITH EVIDENCE; this bounded package does not rebaseline the product roadmap
ARCHITECTURE_GATE_REMAINING: NONE WITH EVIDENCE for this package
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE
MVP1_REMAINING: UNKNOWN WITH EVIDENCE
POST_MVP_KNOWN_INCREMENT: NONE WITH EVIDENCE
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; no accepted Work Log yet
ESTIMATE_CONFIDENCE: MEDIUM
ESTIMATE_AS_OF: 2026-09-07
ESTIMATE_METHOD: bounded file/scope estimate; excludes dependency installation and external waiting

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.5
CORE_EXECUTION: 2.5
TEST_AND_VALIDATION: 0.75
REMEDIATION_BUFFER: 0.25
ESTIMATED_EFFECTIVE_HOURS: 4.0
ESTIMATE_RANGE: 3–6 hours
ESTIMATE_BASIS: bounded middleware, exception rendering, logistics context, tests and documentation
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED

## Mainline reconciliation
- Reconciled onto `origin/main@39a76a461b4e343c2a9152be1ade866cf19e6442` after PR #248 added fulfillment-plan preview.
- The preview path reuses the same request-scoped warehouse/availability context so the new feature does not reintroduce O(N) inventory reads.

- Reconciled again onto `origin/main@39a76a461b4e343c2a9152be1ade866cf19e6442` after PR #249; that accounting change had no overlap with this package.

## Production-equivalent read-only performance evidence — 2026-09-08

The live Production database was exercised read-only by invoking the `/logistics/pending` controller path against 50 rows. The benchmark did not switch the Production release and did not perform writes. Current Production code and the candidate were run alternately against the same live database.

- Current code: 950 SQL queries; warm wall time 1.883–1.946 s; SQL time 786–800 ms.
- Candidate `8973c0c`: 214 SQL queries; warm wall time 0.461–0.464 s; SQL time 175–187 ms.
- Query-count reduction: 736 queries / 77.5%.
- Warm wall-time reduction: approximately 75.8%.
- The repeated 198× warehouse, inventory-sync-batch, and service-region reads collapse to one prepared request context.
- The 50× active-reservation lookup collapses to one batched lookup.
- Residual hotspots remain: 50× `order_external_references` checks and packaging-profile reads. They are follow-up optimization scope, not blockers for this package.

## Latest reconciliation

- Reconciled onto `origin/main@e37d5fa4827384d407ea81e14ada8335e11e87f5`.
- PR #257 loss-to-Finance architecture remains preserved.
- Later mainline changes after PR #257 do not touch the Logistics/Inventory runtime files used by this benchmark.
