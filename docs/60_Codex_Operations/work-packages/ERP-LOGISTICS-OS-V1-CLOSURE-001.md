# ERP-LOGISTICS-OS-V1-CLOSURE-001

## Contract

WORK_PACKAGE_ID: ERP-LOGISTICS-OS-V1-CLOSURE-001
TITLE: Logistics OS V1 — Canonical Shipment Lifecycle Closure
OWNER_AUTHORIZATION: Owner explicitly instructed `执行支线 4 — Logistics OS V1` on 2026-09-05 and explicitly identified 已发待单号 / 运输中 / 异常订单 / 完整 Shipment lifecycle as the remaining V1 scope.
EXECUTION_PROVIDER: US_CODEX_CLI
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

## Extension Implementation + Verification Evidence — 2026-09-05

- Added canonical `logistics_reviews`, `logistics_review_histories`, and immutable `shipment_attempts` persistence while preserving the unique active `shipments.order_id` invariant; attempt evidence includes packaging and carrier-billing JSON snapshots.
- Added review blocking/default and custom destination validation, exception pause/redispatch/reship/contact/cancel actions, completed/cancelled/loss projections, and the eight-tab Logistics status bar.
- Added server-provided customer/KOL order URLs and canonical product URLs across pending, queue, carrier-ledger, review, completed, cancelled, and loss surfaces; Tracking is optional in pending dispatch validation. Internal links use Inertia navigation and the status bar uses exact active matching.
- Focused extension regression: 60 tests / 2109 assertions PASS, including the existing Logistics, Manual Fulfillment, Woo Tracking, App Shell, Approved Menu, and Order Saved Views coverage.
- `npm run typecheck`: PASS. `npm run build`: PASS. `git diff --check`: PASS. Review and exception controls are inline and prompt-free.
- Architecture baseline records the new migration hash. The architecture suite still has two pre-existing repository-baseline directory-list mismatches unrelated to this Work Package; no unrelated baseline refresh was applied.
- Production deploy: NOT performed. Historical Logistics Import: NOT started. PR remains Draft and merge remains Owner-UAT gated.

## Recovery Packaging Evidence — 2026-09-05

- `95371d3` is an intermediate revision and is not a merge candidate. It contains the abandoned packaging-history experiment that attempted to add `shipment_attempt_id` to the active packaging table.
- The prior same-provider session `01a07162-7e62-7f31-975c-4e7007d6fa4d` was terminated after the Codex runtime history error `Custom tool call output is missing`; no concurrent provider mutation exists. This recovery session resumed the exact worktree and branch after inspecting the dirty diff.
- Final packaging architecture: `shipment_item_packaging_snapshots` remains active/current-only with its original `UNIQUE(shipment_id, order_item_id)` migration unchanged. Historical packaging and billing are stored only in `ShipmentAttempt.evidence_snapshot.packaging` and `.billing`; active packaging and billing are deleted after the immutable attempt is created.
- `grep -R shipment_attempt_id` across `app`, `database/migrations/2026_09_05_130000_create_logistics_review_and_attempt_history.php`, and `tests/Feature/Logistics/LogisticsOsV1ClosureTest.php`: zero matches.
- Focused strong reship evidence test: 1 test / 15 assertions PASS.
- Full `LogisticsOsV1ClosureTest.php`: 24 tests / 194 assertions PASS.
- Final requested backend regression set: 62 tests / 2,135 assertions PASS (Logistics closure, Manual Fulfillment, WooCommerce Tracking Outbound, App Shell Navigation, Approved Menu Baseline, and Order Saved Views).
- `npm run typecheck`: PASS. `npm run build`: PASS. `git diff --check`: PASS.
- Architecture/hash assertion: updated only the hash for the intentionally changed review/attempt migration; 5/8 architecture tests PASS, with 3 inherited failures consisting of two directory-list baseline mismatches and the unrelated pre-existing `GlobalFeedbackIsland.test.ts` FE hash mismatch.
- Final follow-up revision after this recovery is the Owner-UAT candidate only. PR #189 remains Draft. No merge, Production deployment, or Historical Logistics Import was performed.

PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate [COMPLETE] → MVP1 Existing Operations [IMPLEMENTED] → ERP Inquiry and intervening merged expansions → WooCommerce Inbound → Tracking Outbound → Sync Orchestration → Product / Media → Full Order Workspace → Logistics OS [CURRENT CLOSURE] → KOL / Inventory / Procurement / Accounting / later expansion
CURRENT_TRACK_PHASE_MAP: Logistics Core existing → Pending fulfillment implemented → Awaiting Tracking current → In Transit current → Exceptions current → Logistics V1 Owner UAT → Historical Logistics Import successor
COMPLETED_PHASES: Existing canonical Shipment core; pending shipment queue; fulfillment draft; warehouse recommendation/manual override; Carrier Master; packaging snapshots; ship/reopen; Audit; Manual Sync foundation
CURRENT_PHASE: Logistics OS V1 Closure
CURRENT_WORK_PACKAGE: ERP-LOGISTICS-OS-V1-CLOSURE-001
PACKAGE_STATUS: IMPLEMENTED / VERIFIED / READY FOR OWNER UAT
WAITING_PHASES: Owner UAT; merge; ERP-LOGISTICS-HISTORICAL-IMPORT-M1-001; Production release
BASELINE: origin/main@1d7cd0e22880cf6aea0183c57fc0e0c3da726d8d
GITHUB_STATE: dedicated branch feature/erp-logistics-os-v1-closure-001
SOURCE_CONFLICTS: User-stated earlier main@6f854b9 is superseded by merged PR #188; current GitHub origin/main@1d7cd0e is authoritative.
GLOBAL_GATE: PASS for implementation; merge/deploy remain Owner-gated
PRODUCTION_DEPLOYMENT_STATUS: NO — explicitly excluded from this WP
NEXT_DEPENDENCY: Owner UAT on exact branch SHA; merge remains Owner-gated
EVIDENCE_AS_OF: 2026-09-05

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: planning value 4.0h; range 2.5–6.0h
KNOWN_CURRENT_TRACK_REMAINING: Logistics V1 Owner UAT only; Historical Logistics Import is a separate successor package and not included here
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
ESTIMATE_BASIS: existing canonical Shipment/Logistics Core plus additive review/attempt evidence persistence; no provider integration or real-data migration
OWNER_REVIEW: INCLUDED
MERGE: EXCLUDED
MIGRATION: INCLUDED — additive canonical review/attempt evidence only
MIGRATION_FILE_AUTHORING: INCLUDED
MIGRATION_EXECUTION: INCLUDED in automated RefreshDatabase validation; no real-data migration run
UAT: INCLUDED
DEPLOYMENT: EXCLUDED
## Provider Handoff — 2026-09-05

Initial extension assignment to US Claude Code stopped before tracked product mutation because Claude returned an account session-limit error. Current repository authority permits frozen deterministic implementation by US Codex CLI. Architecture/business behavior is frozen in this WP; provider is serially reassigned to `US_CODEX_CLI` in the same worktree/branch/PR. No concurrent provider mutation exists.

## Owner UAT Extension — 2026-09-05

Owner accepted the initial Logistics V1 queue lifecycle and explicitly extended this same closure WP before merge.

Additional acceptance scope:
- Enforce the global entity-link contract in Logistics: every visible Order number links to the correct customer/KOL Order detail; visible Product/SKU identities link to Product detail when a canonical product exists.
- Add a reusable KOL-style Logistics status bar across Logistics workspaces with: 待发货 / 已发货 / 运输中 / 异常单 / 复核单 / 已完结 / 已取消 / 亏本单.
- Keep categories mutually exclusive except 亏本单, which remains a derived finance projection and never mutates logistics state.
- Add a real review gate for exception handling. Pending review blocks normal fulfillment. Default approval destination is 待发货; reviewer may choose another valid destination subject to invariant validation.
- Exception actions: 暂停订单 / 重新派件→复核 / 重新发货 / 联系用户 / 退款取消.
- Re-shipment MUST preserve prior carrier/tracking/shipped_at/exception truth. Current `shipments.order_id` stays canonical one-to-one; prior fulfillment rounds are frozen into canonical immutable shipment-attempt/history records before resetting the active Shipment.
- Add explicit completion/cancellation projections and actions needed for 已完结 / 已取消.
- Fix the pending-page stale frontend validation: Tracking is optional at dispatch; warehouse + active carrier + service + shipped_at remain required.
- Reuse existing Orders loss projection for 亏本单; do not create a logistics loss status.
- Historical Logistics Import remains excluded and is still the successor WP.

Owner UAT for the initial slice = PASS. Merge remains forbidden until this extension passes focused regression + exact-SHA Owner UAT.
