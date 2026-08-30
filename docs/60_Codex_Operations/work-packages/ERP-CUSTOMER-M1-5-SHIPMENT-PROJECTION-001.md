---
type: work-package
project: BYL ERP
work_package_id: ERP-CUSTOMER-M1-5-SHIPMENT-PROJECTION-001
status: OWNER_UAT_PASS
execution_provider: US_CODEX_CLI
verification_tier: DAILY
baseline: origin/main@843546edc2f1035593bccbe388e1ffca732d3962
owner_uat_required: YES
git_delivery_authorized: NO
production_deployment_authorized: NO
---

# Customer M1.5 — Shipment Projection

## Owner authorization

OWNER_IMPLEMENTATION_AUTHORIZATION: GRANTED by current chat directive on 2026-08-30 to continue Customer M1.5 on the US Mac after M1.4 merge.
GIT_DELIVERY_AUTHORIZATION: GRANTED on 2026-08-30 for commit / push / PR creation only. MERGE remains NOT AUTHORIZED.
PRODUCTION_DEPLOYMENT_AUTHORIZATION: NOT GRANTED.

## Baseline

- GitHub `main` is canonical.
- M1.4 PR #127 is merged at `843546edc2f1035593bccbe388e1ffca732d3962`.
- US canonical main is synchronized to that exact merge commit.
- M1.5 starts in this independent worktree and branch only.
## Frozen product contract

Customer M1.5 is **Fulfillment / Shipment Projection only**.

Canonical relation:

```text
Customer.id
  → Order.customer_id (order_kind = customer)
  → Shipment.order_id
```

Rules:
- Shipment remains the canonical fulfillment / carrier / tracking truth.
- Do not add `customer_id` or copied shipment facts to `customers` or another Customer-owned table.
- Customer pages are read-only consumers of Shipment truth.
- Do not add shipment editing, fulfillment actions, warehouse routing, carrier rules, connectors, returns or automation to Customer M1.5.
- No new Shipment authority, no new shipment table and no shipment schema migration.
- Existing Customer M1.1–M1.4 behavior is a frozen regression baseline.

## Required M1.5 behavior

1. Customer360 keeps the real Shipment Records projection and makes the boundary explicit through a Logistics-owned read projection.
2. Customer360 shipment fields remain: linked Order, Carrier, Tracking Number, Shipped At and Status.
3. Customer360 shipment count is the real count of canonical Shipment rows linked through customer Orders; the display may remain capped at 20 while count remains uncapped.
4. Purchase History keeps Shipment Status projected from the linked canonical Shipment.5. Customer Library adds an optional account-synced `Shipment count` column, default hidden, under the existing Global Data Grid VI V1.1 contract.
6. Shipment counts for list rows must be batch-projected; N+1 shipment queries are forbidden.
7. Customer Groups / Library segmentation adds:
   - `has_shipment` / 已发货客户: at least one `order_kind=customer`, non-cancelled Order has a linked Shipment.
   - `no_shipment_yet` / 尚未发货客户: no qualifying Shipment exists through a non-cancelled customer Order; this includes never-ordered customers, customers whose customer Orders are all cancelled, and customers with valid but not-yet-shipped Orders.
8. Segment evaluation must use explicit Customer → Order → Shipment relations only.
9. Tracking projection is display-only. Do not infer carrier URLs or external provider state that is not canonical.
10. Customer Dashboard KPI set remains unchanged in M1.5.

## Architecture contract

Add a Logistics-owned read projection:

```text
app/Modules/Logistics/Application/CustomerShipmentProjection.php
```

It should provide deterministic read operations sufficient for:
- customer shipment count;
- customer shipment records;
- shipment status for a bounded set of recent Order IDs;
- shipment counts for a bounded page of Customer IDs.

CustomerController consumes this projection instead of directly importing the Shipment model or issuing raw shipment-table reads for Customer360/List projection. CustomerService may use the existing Order → Shipment relation for dynamic segment filtering.
## Expected file scope

- `app/Modules/Logistics/Application/CustomerShipmentProjection.php`
- `app/Modules/Customers/Application/CustomerService.php`
- `app/Modules/Customers/Experience/Controllers/CustomerController.php`
- `resources/js/Pages/Customers/Library/Index.vue`
- focused Customer frontend tests
- focused Customer backend tests
- this Work Package

Do not modify Support, Marketing, KOL, Inquiry, Inventory, warehouse routing, carrier selection, shipment write services or production configuration unless an M1.5-owned compile/test regression proves a bounded compatibility edit is required.

## Acceptance criteria

- `CustomerController` has no direct `Shipment` model dependency for M1.5 projection logic.
- Shipment facts remain sourced only from canonical `shipments` rows through explicit customer Orders.
- Cross-customer Shipment leakage is impossible in tests.
- Shipment count is uncapped even when display is capped.
- Purchase History shipment status is correct.
- `has_shipment` and `no_shipment_yet` obey the frozen semantics, including cancelled-order behavior.
- Customer Library shipment count is page-batched with no N+1.
- Shipment count column follows existing account preference sanitation and is default hidden.
- Customer360 remains read-only for shipment data.
- Existing Subscription full-width row, Support full-width row, Support behavior and Audit remain intact.
- No production data, fake external shipment source, or production deployment is introduced.
## Verification contract

Required focused backend coverage:
- shipment records only through matching `orders.customer_id`;
- no shipment leak from another Customer;
- uncapped shipment count with capped display;
- purchase-history shipment status projection;
- `has_shipment` positive and negative cases;
- `no_shipment_yet` for no Order, cancelled-only Order and valid unshipped Order;
- cancelled Order with Shipment does not qualify `has_shipment`;
- page-batched shipment count projection.

Required focused frontend coverage:
- Shipment count optional grid column exists and is default hidden;
- Customer Group shortcuts/filter expose 已发货客户 and 尚未发货客户;
- Customer360 Shipment Records remain read-only and render linked Order / Carrier / Tracking / Shipped At / Status;
- existing Subscription + Support full-width layout remains unchanged.

Required gates:
- focused Customer + Logistics backend tests PASS;
- focused Customer frontend Vitest PASS;
- `npm run typecheck` PASS;
- fresh `npm run build` PASS;
- `git diff --check` PASS;
- architecture/provider tests for M1.5-owned files PASS or unrelated baseline drift explicitly classified;
- fresh US-local browser/runtime UAT preparation on an unused port.
## Parallel execution declaration

WORK_PACKAGE_ID: ERP-CUSTOMER-M1-5-SHIPMENT-PROJECTION-001
BRANCH: feature/erp-customer-m1-5-shipment-projection-001
WORKTREE: /Users/a/AI/007/worktrees/erp-customer-m1-5-shipment-projection-001
AGENT_SESSION: US_CODEX_CLI
BASELINE: origin/main@843546edc2f1035593bccbe388e1ffca732d3962
EXPECTED_FILE_SCOPE: Customer shipment projection + Customer segment/list/detail wiring + focused tests
DEPENDENCIES: Customer M1.4 PR #127 merged
DEPENDENCY_TYPE: MERGED_PR
DEPENDENCY_STATUS: SATISFIED
PARALLEL_SAFE: YES; existing Claude session is confined to the separate KOL M1.4B worktree and must not be touched.
FOUNDATION_CHANGE_REQUIRED: NO

## Owner UAT result

OWNER_UAT_STATUS: PASS
OWNER_UAT_ACCEPTED_AT: 2026-08-30T18:11:00+08:00
OWNER_UAT_EVIDENCE: Owner explicitly replied `pass` after reviewing the authenticated US-local M1.5 UAT runtime on `http://127.0.0.1:18118/customers/library/1`.
GIT_DELIVERY_AUTHORIZATION: GRANTED on 2026-08-30 for commit / push / PR creation only. MERGE remains NOT AUTHORIZED.

## Git / UAT gate

Implementation and local verification are authorized. Git delivery is not.
After automated verification, prepare a fresh US-local Owner UAT runtime and stop for Owner UAT.
Do not commit, push, open a PR, merge, tag, release or deploy until a later explicit Owner authorization grants that exact action.

## Owner UAT runtime

- US-local isolated runtime: `http://127.0.0.1:18118`
- Database: disposable SQLite fixture only; no production data.
- Login: `admin@local.test` / local UAT password.
- Runtime smoke: authenticated Library / segment / Customer360 responses PASS.
- Main fixture: Shipment total 23; Customer360 records 20; Support regression row 1.
- Git delivery remains NOT AUTHORIZED.

## PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate [COMPLETE] → prior MVP1 operational foundations [COMPLETE through current merged main] → Owner-reactivated Customer V1 track → retained parallel/paused tracks → milestone remote release / later expansion.
CURRENT_TRACK_PHASE_MAP: Customer M1.1 [MERGED] → M1.2 [MERGED] → M1.3 [MERGED PR #125] → M1.4 [MERGED PR #127] → M1.5 Shipment Projection [CURRENT] → M1.6 Advanced Segmentation → M1.7 Manual Groups → M1.8 Full Import/Export → M1.9 Customer360 Closure.
COMPLETED_PHASES: Customer M1.1, M1.2, M1.3, M1.4.
CURRENT_PHASE: Customer M1.5 Shipment Projection.
CURRENT_WORK_PACKAGE: ERP-CUSTOMER-M1-5-SHIPMENT-PROJECTION-001.
PACKAGE_STATUS: OWNER_UAT_PASS / GIT_DELIVERY_AUTHORIZED / MERGE_NOT_AUTHORIZED.
WAITING_PHASES: Customer M1.6–M1.9 and all excluded scopes.
BASELINE: origin/main@843546edc2f1035593bccbe388e1ffca732d3962.
GITHUB_STATE: main includes M1.4 PR #127 at `843546edc2f1035593bccbe388e1ffca732d3962`; M1.5 branch/worktree is local only.
SOURCE_CONFLICTS: `AI/CURRENT_CONTEXT.md` remains stale on a prior KOL-first sequence; current Owner directive explicitly selects Customer M1.5. Preserve the stale document as historical/conflicting evidence and do not rewrite unrelated governance in this package.
GLOBAL_GATE: M1.5 implementation and US-local verification authorized; Git delivery, merge and deployment remain gated.
PRODUCTION_DEPLOYMENT_STATUS: NO WITH EVIDENCE; explicitly excluded by this package.
NEXT_DEPENDENCY: Complete M1.5 implementation + US-local verification + Owner UAT, then stop for Git-delivery authorization.
EVIDENCE_AS_OF: 2026-08-30; GitHub PR #127 merge, synchronized US main, current Owner directive, repository governance and frozen Customer M1.5 decisions.

## ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: planning value 4h; range 3–6h.
KNOWN_CURRENT_TRACK_REMAINING: UNKNOWN WITH EVIDENCE; M1.6–M1.9 are named but not all have complete non-overlapping estimates.
ARCHITECTURE_GATE_REMAINING: 0h / complete per current repository baseline.
MVP1_TOTAL_ESTIMATE: historical repository planning baseline retained; not recomputed by this package.
MVP1_PLANNING_BASELINE: historical repository planning baseline retained; not recomputed by this package.
MVP1_MAXIMUM_ENVELOPE: historical repository planning envelope retained; not recomputed by this package.
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; accepted actual-hours ledger/overlap map is incomplete.
POST_MVP_KNOWN_INCREMENT: historical classification only; not recomputed by this package.
LONG_TERM_CUMULATIVE_RANGE: historical cumulative horizons remain non-additive.
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE; stale roadmap priority plus incomplete actual/overlap mapping prevents safe subtraction.
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; project-wide accepted ledger incomplete.
ESTIMATE_CONFIDENCE: HIGH for bounded M1.5 package; UNKNOWN for program remaining.
ESTIMATE_AS_OF: 2026-08-30.
ESTIMATE_METHOD: frozen read-projection scope, no schema migration, existing Customer/Shipment UI foundations, bounded tests and UAT preparation.
## ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.5h planning value; range 0.3–0.8h.
CORE_EXECUTION: 2.0h planning value; range 1.5–3.0h.
TEST_AND_VALIDATION: 1.0h planning value; range 0.7–1.5h.
REMEDIATION_BUFFER: 0.5h planning value; range 0.5–0.7h.
ESTIMATED_EFFECTIVE_HOURS: 4.0h.
ESTIMATE_RANGE: 3.0–6.0h.
ESTIMATE_BASIS: existing canonical Shipment model and Customer360 shell; frozen read-only projection + two segments + one optional grid column.
OWNER_REVIEW: INCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: INCLUDED
DEPLOYMENT: EXCLUDED
