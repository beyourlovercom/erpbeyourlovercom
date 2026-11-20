# ERP-SIX-GRID-BULK-RETURN-ALLOCATION-20261120 — R1–R4 Work Package

## Owner scope and gates

- Owner approved the visual proposal (`PASS`) and R1–R4 implementation on 2026-11-20, then explicitly authorized pushing code/tests to `beyourlovercom/erpbeyourlovercom` and verifying on the US Mac mini isolated Dev.
- Six Global Grid views: Domestic `review` / `stockout` / `pending`, and Overseas `review` / `stockout` / `pending`. After selecting rows, the single Grid batch footer offers **批量到待分仓** and its Apply action.
- Owner explicitly removed all status restrictions on 2026-11-20. Authorized operators can force any selected order—including shipped, completed, archived, soft-deleted, and A/B/C fulfillment—back into the canonical pending-allocation queue, clearing the current shipment warehouse, carrier/service, tracking, review/stockout markers, and active reservation in one transaction.
- Preserve historical dispatched shipment facts in immutable shipment attempts (including packaging/billing evidence) before clearing the current shipment; reopen the local manual website-sync flag, but do not call OMS/Woo or invent provider cancellation. An A/B/C child returns via its parent queue entry and only that child plus its parent reset; selecting the parent resets the full fulfillment family. Each affected record has workflow/route history and audit.
- No new migration, external provider dispatch, Woo write, real-order mutation, Production runtime change, or release has occurred during Dev preparation.

## R1–R4 implementation and validation

1. **R1 UI:** Domestic and Overseas pages share a selectable bulk footer on the six requested states, retaining existing Global Grid settings, permissions, page actions, and 30-tab Workspace defaults.
2. **R2 mutation:** Separate domestic and overseas POST routes dispatch to one transactional service. HTTP permissions, max 50 distinct IDs, per-row success/error, auditable historical-dispatch snapshots, archive reactivation, split-family consistency, and canonical queue readback are enforced.
3. **R3 automated:** US Mac mini isolated worktree at `/Users/a/ai/uat/erp-bulk-return-20261120`; focused Laravel test 3 PASS / 64 assertions; logistics regression 105 PASS / 2,126 assertions; focused Vue 122 PASS across three files; `npm run typecheck` PASS; `npm run build` PASS, including `ERP_WORKSPACE_TABS_BUILD_PASS`.
4. **R4 real Local/UAT:** Isolated SQLite and local-only synthetic seeder (six `UAT-BATCH-*` orders), loopback server `127.0.0.1:18083`; approved temporary `admin` / `password` credential. All six real Global Grid pages have been opened, seeded row selected, the **批量到待分仓** option chosen, and Apply button confirmed visible. **Owner live-page UAT PASS is pending.**

## Delivery

- One feature branch: `feat/erp-six-grids-bulk-return-allocation-20261120`, based on current `main` (`e7b93bca` at implementation sync).
- `main` subsequently advanced to `297bbd7e` with PR #817 selected-only pending-order exports. The merge candidate preserves both selected download and **批量到待分仓** actions in each pending Grid, with combined regression assertions.
- The Orders/KOL mutation-contract workflow is extended to run the added backend/UI regression tests on the exact PR head.
- Draft PR #818 is under CI. Owner later requested to continue through release, but **do not merge before Owner live-page UAT PASS**; Production rollout still requires the exact candidate SHA, rollback and post-release readback.

## Acceptance readback

For every successful row, assert `workflow_status=pending_allocation`, `routing_warehouse_code=UNASSIGNED`, shipment `warehouse_id=NULL`, `carrier=NULL`, `service=NULL`, `tracking_number=NULL`, membership in `LogisticsStatusSummary::pendingAllocationQuery()`, and corresponding workflow, warehouse-route and audit records. Negative cases must preserve original warehouse and dispatch history.
