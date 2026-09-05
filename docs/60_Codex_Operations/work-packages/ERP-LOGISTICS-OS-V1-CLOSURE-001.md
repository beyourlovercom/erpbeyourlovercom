# ERP-LOGISTICS-OS-V1-CLOSURE-001

## Contract

WORK_PACKAGE_ID: ERP-LOGISTICS-OS-V1-CLOSURE-001
TITLE: Logistics OS V1 — Canonical Shipment Lifecycle Closure
OWNER_AUTHORIZATION: Owner explicitly instructed `执行支线 4 — Logistics OS V1` on 2026-09-05 and explicitly identified 已发待单号 / 运输中 / 异常订单 / 完整 Shipment lifecycle as the remaining V1 scope.
EXECUTION_PROVIDER: CLAUDE_CODE
BASELINE: origin/main@1d7cd0e22880cf6aea0183c57fc0e0c3da726d8d
BRANCH: feature/erp-logistics-os-v1-closure-001
DEPENDENCIES: NONE beyond merged main. Product closure, Woo first import, Inventory first snapshot, Accounting, and Historical Logistics Import are not implementation dependencies for this WP.
DEPENDENCY_TYPE: NONE
DEPENDENCY_STATUS: SATISFIED
PARALLEL_SAFE: YES
FOUNDATION_CHANGE_REQUIRED: NO
VERIFICATION_TIER: DAILY

## Objective

Close Logistics OS V1 without creating a second logistics truth store. Preserve `orders + shipments` as canonical truth and implement the three Owner-approved missing Logistics surfaces as projections/actions over the canonical Shipment lifecycle:

1. `/logistics/awaiting-tracking` — 已发待单号.
2. `/logistics/in-transit` — 运输中.
3. `/logistics/exceptions` — 异常订单.

The existing `/logistics/pending`, Carrier Master, warehouse recommendation, fulfillment draft, packaging snapshots, ship/reopen, Audit, and Manual Sync foundations remain authoritative and must be extended rather than replaced.

## Frozen lifecycle contract

Canonical `shipments.shipment_status` remains the lifecycle authority and no legacy logistics table may be introduced.

### A. Pending shipment

- Order: `order_status = pending_shipment`.
- Shipment may be absent or `shipment_status = pending`.
- Existing `/logistics/pending` fulfillment workspace remains the operating surface.

### B. Dispatched / awaiting tracking

- Operator may confirm physical dispatch even when the tracking number is not yet available.
- Required dispatch truth: warehouse, active carrier, service, shipped_at.
- `tracking_number` may be null/blank at dispatch.
- Order becomes `order_status = shipped`.
- Shipment becomes `shipment_status = shipped`.
- Queue projection: `shipment_status = shipped` + shipped_at present + tracking_number absent.
- This projection is `/logistics/awaiting-tracking`.
- Manual/Woo tracking sync must NOT be armed solely because dispatch occurred without a tracking number.

### C. Tracking received / in transit

- A shipped shipment may receive/update tracking through a dedicated bounded Logistics action.
- Tracking update preserves warehouse/carrier/service/shipped_at and writes only the tracking correction plus actor/audit evidence.
- After a non-empty tracking number exists, the shipment projects into `/logistics/in-transit`.
- The existing Manual Sync / Woo outbound intent is armed only once the shipment has usable tracking evidence.
- Queue projection: `shipment_status = shipped` + non-empty tracking_number.

### D. Exception

- A dispatched shipment may be marked exception manually with a required exception note/reason stored in the existing canonical logistics note evidence.
- Order remains `order_status = shipped`; the physical shipment is not reopened to pending solely because transport has an exception.
- Shipment becomes `shipment_status = exception`.
- Exception queue is `/logistics/exceptions`.
- Resolving an exception restores `shipment_status = shipped` and preserves dispatch/tracking truth; it must not create a duplicate Shipment.
- Mark-exception and resolve-exception transitions must be audited and idempotent where the requested canonical state already exists.

### E. Reopen for shipment correction

- Existing `ShipmentService::reopen()` remains the correction path that moves the order back to `pending_shipment`, clears dispatch/tracking state as currently contracted, and reopens Manual Sync.
- This WP must not silently change reopen semantics into exception handling.

## UI / route scope

Implement real server-backed routes using `logistics.view` / `logistics.operate`:

- GET `/logistics/awaiting-tracking` name `logistics.awaiting-tracking`.
- GET `/logistics/in-transit` name `logistics.in-transit`.
- GET `/logistics/exceptions` name `logistics.exceptions`.
- POST bounded tracking update action for a shipped Order/Shipment.
- POST mark-exception action.
- POST resolve-exception action.

Use one reusable queue component where practical; do not create three independent business implementations.

Required queue information:

- ERP / external order identity.
- recipient / country where already available from canonical Order address.
- warehouse.
- carrier + service.
- tracking number.
- shipped time.
- logistics note / exception reason.
- direct action to the appropriate lifecycle operation.

Required queue behavior:

- pagination, default 50 per page, following existing Logistics style.
- awaiting-tracking supports entering/saving tracking.
- in-transit supports copy tracking and mark exception.
- exceptions supports resolving exception and preserving shipment truth.
- no provider API polling, carrier webhook, automatic delivery detection, or WMS automation.

## Menu baseline authorization

Owner authorization for Logistics OS V1 includes activating the already-approved planned menu surfaces. Update only these approved Logistics menu facts:

- `logistics`: status `implemented`, progress `100` when all V1 acceptance gates pass.
- `logistics-awaiting-tracking`: permission `logistics.view`, status `implemented`, progress `100`.
- `logistics-in-transit`: permission `logistics.view`, status `implemented`, progress `100`.
- `logistics-exceptions`: permission `logistics.view`, status `implemented`, progress `100`.

Labels, URL slugs, route names, sort order, emoji, parent relationship and approved function text remain unchanged unless a compile/test repair requires an exact compatibility adjustment.

## Data / schema policy

- Prefer the existing Shipment schema and existing `shipment_status`, tracking, shipped_at and logistics_note columns.
- No new legacy/historical logistics table.
- No Historical Shipment importer in this WP.
- No automatic shipment import.
- No provider tracking status API.
- No delivery-event table.
- No migration unless implementation proves the frozen lifecycle cannot be represented safely with existing canonical columns. If a migration becomes necessary, STOP and return to GPT/Owner because that would change the approved verification risk classification.

## Explicit exclusions

- `ERP-LOGISTICS-HISTORICAL-IMPORT-M1-001`.
- Woo historical Order import.
- Product/SKU reconciliation.
- Inventory snapshot import.
- Accounting / order profit.
- carrier API integration or paid tracking API.
- delivery confirmation automation.
- split shipments / multiple shipments per order.
- multi-package tracking.
- Production deployment or Production data mutation.

## Acceptance criteria

1. Existing pending fulfillment still works with warehouse recommendation/manual override, Carrier Master options, draft saving, packaging snapshot capture, Audit and reopen.
2. Dispatch without tracking is accepted when warehouse/carrier/service/shipped_at are valid.
3. Such dispatch appears only in 已发待单号 and does not prematurely arm tracking sync.
4. Entering tracking moves the same Shipment from 已发待单号 to 运输中 without creating a duplicate Shipment.
5. Tracking update arms existing Manual Sync / Woo tracking outbound intent using the same Order/Shipment truth.
6. Marking an in-transit shipment exception moves the same Shipment to 异常订单 while preserving order identity, dispatch evidence and tracking.
7. Resolving exception returns the same Shipment to 运输中 (or 已发待单号 if tracking is absent) without changing the Order back to pending shipment.
8. Existing explicit reopen still returns shipment/order to pending shipment correction flow.
9. All lifecycle state-changing actions record immutable Audit evidence.
10. Unauthorized users cannot view/operate these routes beyond existing Logistics permission contracts.
11. Menu baseline shows the four Logistics V1 surfaces as implemented only after routes/tests exist.
12. No schema migration, legacy logistics truth table, historical importer, provider polling or Production mutation is introduced.

## Validation contract

US Claude implementation host must run the complete package validation on the merge-candidate revision:

- focused Logistics backend Feature tests covering all four queues/transitions.
- existing MVP1 fulfillment/manual-sync/Woo tracking regression relevant to ShipmentService changes.
- App Shell navigation + Approved Menu baseline tests.
- focused Vue/Vitest for Logistics pending + queue component.
- `npm run typecheck` or repository-equivalent Vue TypeScript check.
- production frontend build.
- `git diff --check`.
- local `http://erp.b` browser/runtime smoke for the four Logistics pages and at least one synthetic lifecycle: pending → dispatched without tracking → tracking entered → exception → resolved.

After push, China Codex performs DAILY exact-SHA smoke only: exact revision, clean tracked state, `http://erp.b` reachability, and bounded task-critical route/lifecycle assertions. Do not duplicate the entire US suite unless GPT/Owner escalates.

## Delivery / authorization gates

- One Work Package / one branch / one dedicated worktree / one Draft delivery PR.
- Claude may implement, test, commit, push and maintain the Draft PR only within this frozen scope.
- Claude may not merge.
- Owner UAT PASS is required before merge authorization.
- Production deployment is not authorized by this WP.
- Historical Logistics Import starts as a separate successor WP only after Logistics OS V1 is accepted and canonical historical Orders are available.

PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate [COMPLETE] → MVP1 Existing Operations [IMPLEMENTED] → ERP Inquiry and intervening merged expansions → WooCommerce Inbound → Tracking Outbound → Sync Orchestration → Product / Media → Full Order Workspace → Logistics OS [CURRENT CLOSURE] → KOL / Inventory / Procurement / Accounting / later expansion
CURRENT_TRACK_PHASE_MAP: Logistics Core existing → Pending fulfillment implemented → Awaiting Tracking current → In Transit current → Exceptions current → Logistics V1 Owner UAT → Historical Logistics Import successor
COMPLETED_PHASES: Existing canonical Shipment core; pending shipment queue; fulfillment draft; warehouse recommendation/manual override; Carrier Master; packaging snapshots; ship/reopen; Audit; Manual Sync foundation
CURRENT_PHASE: Logistics OS V1 Closure
CURRENT_WORK_PACKAGE: ERP-LOGISTICS-OS-V1-CLOSURE-001
PACKAGE_STATUS: OWNER AUTHORIZED / IMPLEMENTATION READY
WAITING_PHASES: Owner UAT; merge; ERP-LOGISTICS-HISTORICAL-IMPORT-M1-001; Production release
BASELINE: origin/main@1d7cd0e22880cf6aea0183c57fc0e0c3da726d8d
GITHUB_STATE: dedicated branch feature/erp-logistics-os-v1-closure-001
SOURCE_CONFLICTS: User-stated earlier main@6f854b9 is superseded by merged PR #188; current GitHub origin/main@1d7cd0e is authoritative.
GLOBAL_GATE: PASS for implementation; merge/deploy remain Owner-gated
PRODUCTION_DEPLOYMENT_STATUS: NO — explicitly excluded from this WP
NEXT_DEPENDENCY: US Claude implementation and full package verification
EVIDENCE_AS_OF: 2026-09-05

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: planning value 4.0h; range 2.5–6.0h
KNOWN_CURRENT_TRACK_REMAINING: Logistics V1 implementation/test + Owner UAT; Historical Logistics Import is a separate successor package and not included here
ARCHITECTURE_GATE_REMAINING: 0h for this package; architecture foundation already exists
MVP1_TOTAL_ESTIMATE: historical / not recomputed by this WP
MVP1_PLANNING_BASELINE: historical / not recomputed by this WP
MVP1_MAXIMUM_ENVELOPE: historical / not recomputed by this WP
MVP1_REMAINING: UNKNOWN WITH EVIDENCE — this package does not recompute unrelated MVP1 remaining effort
POST_MVP_KNOWN_INCREMENT: Historical Logistics Import excluded
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE — unrelated program horizons not recalculated
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE — unrelated program packages remain
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE — no accepted project-wide actual ledger recomputation in this WP
ESTIMATE_CONFIDENCE: MEDIUM-HIGH
ESTIMATE_AS_OF: 2026-09-05
ESTIMATE_METHOD: bounded existing-core extension; no schema/provider integration; reuse one queue component

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.5h planning value; 0.25–0.75h
CORE_EXECUTION: 2.0h planning value; 1.25–3.0h
TEST_AND_VALIDATION: 1.0h planning value; 0.75–1.5h
REMEDIATION_BUFFER: 0.5h planning value; 0.25–0.75h
ESTIMATED_EFFECTIVE_HOURS: 4.0h
ESTIMATE_RANGE: 2.5–6.0h
ESTIMATE_BASIS: existing canonical Shipment/Logistics Core already implements the difficult fulfillment foundation; V1 closes three queue projections and bounded lifecycle actions without schema/provider work
OWNER_REVIEW: INCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: INCLUDED
DEPLOYMENT: EXCLUDED
