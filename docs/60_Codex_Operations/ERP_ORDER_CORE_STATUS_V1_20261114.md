# ERP Unified Core Order Status V1 — Owner PASS, Issue #765
Baseline: GitHub main 4b5c428d; owner-approved 2026-11-14. Dev-only; no Production writes or release.

## Authoritative rules
- One effective core status per order, provided by existing OrderProgressResolver/OrderWorkflowStatusProjection; no duplicate Shipment truth.
- Priority: archived / cancelled > explicit exception > stockout > factual shipment/partial shipment > allocation/review. Parent A/B/C rollups retain actual segment facts.
- Actions and dropdown call audited HTTP/server mutations with actor permission, DB locking, optimistic revision (409), reason where required, authoritative readback and queue reconciliation.
- Exception flags must not clear tracking/shipped_at, stock reservations or WooCommerce status. Closing exception restores fact-derived status, not a fabricated pre-shipment state.
- Stockout is a real hold; domestic review and overseas Dede review keep their own approval gates. Never fabricate shipping/arrival/completion from a label.
- Cancel is real local orders.order_status=cancelled and requires confirmation/reason. Never claim refund or automatically update Woo status.
- Primary queues are exclusive; All/Today/analytics views remain cross-cutting. Deleted/archived/cancelled must not enter active fulfillment queues.
- Existing 30 Workspace Tabs, Global Grid, compact UI, Woo, finance, MorePlus and original shipment contracts are frozen.

## Verified baseline mismatches
- OrderService::setOperationalMarker writes generic exception, in_transit, return and stockout annotations, while OrderProgressResolver ignored generic exception marker.
- Customer dropdown currently allows only limited mutable phases; OrderWorkflowStatusPill really uses PATCH but canonical statuses are disabled.
- Orders/Index exception view filters only markers, stockout view additionally requires real-time inventory shortage, Logistics/Exceptions filters only Shipment status=exception.
- Canonical cancellation is orders.order_status=cancelled, separate from source Woo order status.

## Contract R1–R5
R1: enumerate entrypoints and frozen priority/transition/queue matrix.
R2: audited permissioned server-side transition coordinator, reuse canonical warehouse and Shipment services, no phantom evidence.
R3: dropdown + shortcut same transition, meaningful errors and refresh.
R4: primary queue predicates match same effective status, preserve cross-cutting views.
R5: exact-sha HTTP+Vue+typecheck+build+Chrome synthetic UAT, then Owner 007windows visual PASS.

## Core transitions
| Command | Authority | Primary result |
| --- | --- | --- |
| exception / resolve_exception | order operator; audited override | 异常订单 / underlying fact-derived queue |
| out_of_stock / restock | domestic review or overseas Dede; unallocated pre-shipment hold only | 缺货订单 / actionable queue |
| cancelled | local ERP cancellation, explicit reason, no Woo or refund | 已取消订单 |
| shipping / completed | real shipment service only | shipment-derived queue |
| archive | existing archival flow | 归档 |
| in_transit / return | explicit secondary metadata unless verified shipment event | core unchanged |

This design document does not itself authorize real data migrations, production changes, or claim R2–R5 complete.
## PROJECT POSITION
PROGRAM_ROADMAP: Existing BYL ERP roadmap unchanged; this is an incremental order-core-status work package.
CURRENT_TRACK_PHASE_MAP: R0 review complete -> R1 contract -> R2 backend -> R3 UI -> R4 queues -> R5 tests/UAT.
COMPLETED_PHASES: R0 source audit, Owner proposal PASS.
CURRENT_PHASE: R5 exact-SHA verification and Owner 007windows UAT.
CURRENT_WORK_PACKAGE: ERP-ORDER-CORE-STATUS-V1-20261114 / Issue #765.
PACKAGE_STATUS: DEV CODE IMPLEMENTED; CI and OWNER UAT ACCEPTANCE PENDING; NO PRODUCTION RELEASE.
WAITING_PHASES: Final Owner 007windows UAT PASS; separately approved Production deployment.
BASELINE: main 4b5c428d9ff06ad2beac5b2cd245f359bd79b6c8.
GITHUB_STATE: isolated branch feat/erp-order-core-status-v1-20261114, not merged.
SOURCE_CONFLICTS: baseline core-state vs markers vs queues inconsistency evidenced above.
GLOBAL_GATE: Owner initial visual PASS, final live UAT pending.
PRODUCTION_DEPLOYMENT_STATUS: NO.
NEXT_DEPENDENCY: Publish Draft PR exact SHA, inspect CI, Owner 007windows live UAT, separately authorize Production.
EVIDENCE_AS_OF: 2026-11-14.

## ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 31–53 effective hours.
KNOWN_CURRENT_TRACK_REMAINING: R2–R5, plus R1 follow-up.
ARCHITECTURE_GATE_REMAINING: complete code-to-contract mapping and conflict resolution.
MVP1_TOTAL_ESTIMATE: existing ERP program unchanged.
MVP1_PLANNING_BASELINE: existing ERP program unchanged.
MVP1_MAXIMUM_ENVELOPE: existing ERP program unchanged.
MVP1_REMAINING: unknown for overall program.
POST_MVP_KNOWN_INCREMENT: this package 31–53h.
LONG_TERM_CUMULATIVE_RANGE: ERP program unchanged.
PROGRAM_REMAINING: unknown for overall program.
COMPLETED_ACTUAL_EFFECTIVE_HOURS: pending verified work logs.
ESTIMATE_CONFIDENCE: preliminary.
ESTIMATE_AS_OF: 2026-11-14.
ESTIMATE_METHOD: scoped file inventory and guarded UI+API+queue integration.

## ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 5–8h.
CORE_EXECUTION: 18–31h.
TEST_AND_VALIDATION: 8–14h.
REMEDIATION_BUFFER: included in total initial range.
ESTIMATED_EFFECTIVE_HOURS: 31–53h.
ESTIMATE_RANGE: 31–53h.
ESTIMATE_BASIS: R1 5–8, R2 7–12, R3 4–7, R4 7–12, R5 8–14.
OWNER_REVIEW: INCLUDED.
MERGE: EXCLUDED pending UAT.
MIGRATION: EXCLUDED.
UAT: INCLUDED.
DEPLOYMENT: EXCLUDED.


## R5 verification evidence / 2026-11-14
- **Exact branch base**: GitHub main 4b5c428d9ff06ad2beac5b2cd245f359bd79b6c8; checked unchanged before commit.
- **Orders HTTP regressions**: 139/139 PASS, 2094 assertions (including 7 new core-state tests, 50 assertions). Covers actual PATCH permissions, 409 revision conflict, atomic exception/stockout/cancellation, shipped evidence, legacy marker route and split-parent shipment hold.
- **Logistics HTTP regressions**: 699 executed, 697 PASS, 2 pre-existing failures (CompletionSemantics old carrier wording and XLWMS route 401). Both reproduced on unmodified r5-l2 baseline, not introduced here. One skipped.
- **Vue focused**: OrderWorkflowStatusPill + Orders/Show 98/98 PASS; 007windows headless-false Chrome real API click readback: 3 explicit core transitions on synthetic UAT order IDs 8/9/10 returned exception / out_of_stock / cancelled, matching isolated SQLite state.
- **Vue full CI-equivalent env**: 923 executed, 916 PASS, 7 pre-existing Inventory suite failures; exact main 4b5c428d independently reproduces the same 7 failing Inventory tests (921 tests baseline). Do not claim global full-suite PASS.
- **Frontend typecheck**: vue-tsc no errors. Vite build: PASS; ERP_WORKSPACE_TABS_BUILD_PASS 30-tab default-on bundle. git diff --check PASS.
- **Owner visual gate**: 007windows authenticated separate Chrome /customers/orders/8 /9 /10 opened; Owner manual verification PASS still required before merge/closure. Only local sqlite UAT database, no Production or real-order mutations.
- **Rollback**: branch not merged, Production untouched; local UAT isolated to 100.94.52.109:8765 and /tmp/byl-erp-core-status-uat-20261114.sqlite.
- **Known residual**: full-suite unrelated baseline failures remain; additional CI statuses and cross-module Owner UAT must be reviewed. No automatic merge or deployment.
