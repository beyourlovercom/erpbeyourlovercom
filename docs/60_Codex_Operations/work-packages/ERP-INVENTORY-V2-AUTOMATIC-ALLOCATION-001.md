# ERP-INVENTORY-V2-AUTOMATIC-ALLOCATION-001

STATUS: OWNER_LOCAL_UAT_PASS / MERGE_READY
EXECUTION_PROVIDER: GPT_WEB
BASELINE: origin/main@5d7a694a1e510d3ff91c38260e84751018aec35e
BRANCH: feature/erp-inventory-v2-automatic-allocation-001
WORKTREE: /Users/a/AI/007/worktrees/erp-inventory-v2-automatic-allocation-001
VERIFICATION_TIER: HIGH_RISK
PARALLEL_SAFE: YES
DEPENDENCIES: Reservation Foundation + Warehouse Recommendation + Assisted Allocation + Automatic Fulfillment Readiness M1 merged to main
DEPENDENCY_TYPE: MERGED_PR

## Objective

Upgrade assisted warehouse allocation into bounded Automatic Allocation:

`Pending Order → Eligibility → Warehouse Recommendation → Reservation → Shipment Draft`

Automatic Allocation stops at Reservation + Shipment Draft. It never marks shipped and never decrements QOH.

## Owner-frozen routing policy

First release is conservative country allowlist V1:
- `DE` destination may auto-allocate only to authoritative `DE` warehouse.
- `US` remains Assisted-only until subdivision-aware routing exists.
- Other countries remain Assisted-only until explicit Owner-approved routing exists.
- `CA`, `FACTORY`, and `007` remain excluded until authoritative Full Snapshot inventory and routing policy exist.
- No cross-region automatic fallback.
- No split shipment.
## Production audit baseline

Read-only audit on 2026-09-06:
- Pending operational Orders: 418.
- Historical `historical_logistics` excluded Orders: 765.
- Full Snapshot warehouses: US-LA / US-NY / DE.
- `warehouse_service_regions`: 0.
- Recommendation audit: 417 `service_regions_unconfigured`; 1 `unresolved_product`.
- Order 718 / `007erp-0705-c718` / Woo 24878 / PH / Sticker Design remains unresolved and blocked.
- Reservations: 0; reservation items: 0; Shipments: 0.
- Inventory: US-LA QOH 2678; US-NY QOH 1184; DE QOH 1051; all reserved 0.

Counterfactual stock coverage before routing:
- 158 Orders fit US-LA + US-NY + DE.
- 50 fit US-LA + DE.
- 38 fit only US-LA.
- 21 fit US-LA + US-NY.
- 10 fit only DE.
- 1 fits only US-NY.
- 139 fit none of the three authoritative warehouses.
- 1 unresolved Product.

Current DE destination population: 19 Orders; 9 currently fit DE inventory and 10 do not.

## Automatic allocation gates

Default configuration is fail-closed:
`INVENTORY_AUTO_ALLOCATION_ENABLED=false`
`INVENTORY_AUTO_ALLOCATION_DRY_RUN=true`
`INVENTORY_AUTO_ALLOCATION_COUNTRY_ALLOWLIST=DE`
`INVENTORY_AUTO_ALLOCATION_SCHEDULER_ENABLED=false`
## Eligibility contract

Canonical result states:
- `READY_AUTO_ALLOCATE`
- `ALREADY_RESERVED`
- `ALREADY_HAS_DRAFT`
- `MANUAL_WAREHOUSE_SELECTED`
- `HISTORICAL_EXCLUDED`
- `COUNTRY_NOT_AUTO_ENABLED`
- `MISSING_COUNTRY`
- `INVALID_COUNTRY`
- `UNRESOLVED_PRODUCT`
- `NO_SERVICE_REGION`
- `INSUFFICIENT_STOCK`
- `NO_FULL_SNAPSHOT_AUTHORITY`
- `MULTIPLE_POLICY_AMBIGUITY`
- `BLOCKED_OTHER`

Every blocked Order must carry one explicit reason. The eligibility layer reuses `WarehouseRecommendationService`; it does not fork inventory/routing logic.

## Mutation invariants

- Server recomputes eligibility/recommendation immediately before mutation.
- Lock canonical Order before allocation.
- Reservation + Shipment Draft are one outer transaction.
- Replay is idempotent: one Order → at most one active Reservation and one canonical Shipment.
- `warehouse_selection_source = automatic` for system allocation.
- Same-warehouse later edits preserve `automatic`; manual warehouse override releases reservation then becomes `manual`.
- Allocation changes only `quantity_reserved`; QOH remains unchanged.
- Historical Orders and unresolved Products are permanently fail-closed.
## Trigger / preview contract

- Catch-up scheduler scans a bounded pending operational population and calls the same canonical service.
- Manual preview/command uses the same eligibility service.
- Woo-event wiring may be added only without colliding with the active Accounting inbound work; scheduler guarantees recovery meanwhile.
- Dry-run never creates Reservation, Shipment, adjustment, or other business writes.
- Production deploy and Production automatic apply remain separate Owner gates.

## Approved visible behavior

`/logistics/pending` extends the existing Assisted Fulfillment card only:
- Ready: `自动分配 · 可执行` + proposed warehouse + Preview/no-write explanation.
- Blocked: `自动分配 · 暂阻塞` + exact reason; existing manual warehouse selection remains usable.
- Allocated: `自动分配 · 已完成` + warehouse + inventory reserved explanation.
- No full-page refresh is introduced.

Owner returned `pass` for this routing policy and UI proposal on 2026-09-06.

## Acceptance criteria

1. One canonical Warehouse Recommendation engine is reused.
2. Only country allowlist `DE` is automatic in V1.
3. Historical Orders are excluded.
4. Full Snapshot authority is mandatory.
5. Whole Order must fit one warehouse.
6. Reservation + Shipment Draft are atomic and idempotent.
7. QOH is unchanged at allocation; reserved changes exactly.
8. Automatic provenance survives same-warehouse edits and shipment confirmation; manual override becomes manual after releasing reservation.
9. Dry-run preview reports exact blocked reasons and proposed warehouse.
10. Focused Inventory/Logistics tests, typecheck/build and Local/UAT pass.
11. Production deploy and Production apply are not performed by this package without separate Owner authorization.

## Expected file scope

Primary additions:
- `app/Modules/Logistics/Application/AutomaticAllocation*`
- `app/Modules/Logistics/Experience/Console/*`
- `config/inventory_auto_allocation.php`
- focused tests + this Work Package.

Minimal shared changes allowed:
- `InventoryReservationService` for authenticated/system actor support.
- `AssistedFulfillmentService` for `automatic` provenance preservation.
- `LogisticsController` / `Logistics/Index.vue` for approved status projection.
- `bootstrap/app.php` / `routes/console.php` for bounded command/scheduler registration.

Avoid Accounting Woo normalizer/OrderService and unrelated Packaging implementation.

## Verification evidence — 2026-09-06

- Automatic Allocation focused suite: 8 tests / 46 assertions / PASS, including explicit CLI dry-run and fail-closed apply gates.
- Combined Logistics + Automatic Fulfillment Readiness + Assisted Fulfillment + Reservation regression: 96 tests / 788 assertions / PASS.
- Logistics page Vitest: 7 tests / PASS.
- TypeScript `vue-tsc --noEmit`: PASS.
- Vite production build: PASS; existing large-chunk warning only.
- CLI temp-SQLite smoke: explicit `--dry-run` returned `mutated=0`; default-gated `--apply` rejected with non-zero exit.
- `git diff --check`: PASS.
- Architecture: 22 tests / 196 assertions / 3 known repository snapshot-drift failures. These are the same three failure IDs documented on merged PR #223/current main; this package does not widen scope to rewrite unrelated global baselines.
- Production mutation during implementation/verification: 0.
- Owner Local/UAT PASS on 2026-09-06 after live verification of DONE-DE / READY-DE / BLOCKED-US states.
- UAT-found 419 on the no-refresh adopt-recommendation POST was fixed by canonical CSRF token propagation; live browser retry created Reservation + Shipment Draft, updated warehouse inline without page reload, kept QOH unchanged, and passed Owner retest.

## Current gate

`CANONICAL_RECONCILIATION = COMPLETE`
`ROUTING_POLICY = OWNER_PASS`
`PRE_DEVELOPMENT_VISUAL = OWNER_PASS`
`IMPLEMENTATION = COMPLETE`
`FOCUSED_VERIFICATION = PASS`
`OWNER_LOCAL_UAT = PASS`
`PRODUCTION_DEPLOY = NOT_AUTHORIZED`
`PRODUCTION_AUTO_APPLY = NOT_AUTHORIZED`

PROJECT POSITION
PROGRAM_ROADMAP: Real Data Onboarding COMPLETE → Fulfillment Intelligence CURRENT → Automatic Fulfillment WAITING → Accounting feedback / hardening WAITING
CURRENT_TRACK_PHASE_MAP: AA1 COMPLETE → AA2 OWNER_PASS → AA3/AA4/AA5 IMPLEMENTATION+VERIFICATION COMPLETE → OWNER LOCAL/UAT PASS → PRODUCTION DEPLOY GATE CURRENT → PRODUCTION APPLY CANARY GATE → AUTOMATIC MODE
COMPLETED_PHASES: AA1 canonical audit; AA2 routing policy + visual proposal; AA3 eligibility/orchestrator; AA4 command/scheduler; AA5 UI status; focused verification
CURRENT_PHASE: Production Release Gate
CURRENT_WORK_PACKAGE: ERP-INVENTORY-V2-AUTOMATIC-ALLOCATION-001
PACKAGE_STATUS: OWNER_LOCAL_UAT_PASS / MERGE_READY
WAITING_PHASES: Production deploy gate; Production canary/apply gate
BASELINE: origin/main@5d7a694a1e510d3ff91c38260e84751018aec35e
GITHUB_STATE: PR #224 merge-ready after Owner Local/UAT PASS; branch rebased onto main@5d7a694 after merged PR #225
SOURCE_CONFLICTS: AI/CURRENT_CONTEXT is stale versus current main; main advanced from 3aa26cf to 069b00b via PR #223. PR #223 explicitly defines Line 1 as Recommendation → Reservation → Shipment Draft and Line 4 as post-allocation readiness, matching this package boundary.
GLOBAL_GATE: OWNER_LOCAL_UAT_PASS / MERGE_AUTHORIZED
PRODUCTION_DEPLOYMENT_STATUS: NO — no Production deploy or automatic allocation mutation authorized
NEXT_DEPENDENCY: merge PR #224; then Production Release Gate (deploy/apply still separately gated)
EVIDENCE_AS_OF: 2026-09-06

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: planning 5.0 effective hours; range 3.5–7.5
KNOWN_CURRENT_TRACK_REMAINING: 5.0 planning hours before Owner wait time; Production canary/deploy excluded
ARCHITECTURE_GATE_REMAINING: 0 — routing and UI contract frozen
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE — not re-estimated by this package
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE
MVP1_REMAINING: UNKNOWN WITH EVIDENCE
POST_MVP_KNOWN_INCREMENT: Automatic Allocation V1 only, 5.0 planning hours
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE — final Work Log not yet issued
ESTIMATE_CONFIDENCE: MEDIUM-HIGH
ESTIMATE_AS_OF: 2026-09-06
ESTIMATE_METHOD: frozen routing/eligibility contract + existing Reservation/Recommendation/Shipment foundations

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.75 planning hour; range 0.5–1.0
CORE_EXECUTION: 2.75 planning hours; range 2.0–4.0
TEST_AND_VALIDATION: 1.0 planning hour; range 0.75–1.5
REMEDIATION_BUFFER: 0.5 planning hour; range 0.25–1.0
ESTIMATED_EFFECTIVE_HOURS: 5.0
ESTIMATE_RANGE: 3.5–7.5
ESTIMATE_BASIS: additive eligibility/orchestrator/command/config/UI status over merged fulfillment foundations
OWNER_REVIEW: INCLUDED
MERGE: INCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: INCLUDED
DEPLOYMENT: EXCLUDED
