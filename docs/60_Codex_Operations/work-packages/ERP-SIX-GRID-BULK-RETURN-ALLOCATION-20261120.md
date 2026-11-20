# ERP-SIX-GRID-BULK-RETURN-ALLOCATION-20261120 — R1–R4 Work Package

## Owner scope and gates

- Owner approved the visual proposal (`PASS`) and R1–R4 implementation on 2026-11-20, then explicitly authorized pushing code/tests to `beyourlovercom/erpbeyourlovercom` and verifying on the US Mac mini isolated Dev.
- Six Global Grid views: Domestic `review` / `stockout` / `pending`, and Overseas `review` / `stockout` / `pending`. After selecting rows, the single Grid batch footer offers **批量到待分仓** and its Apply action.
- An authorized operator can force selected *unshipped, non-split* orders back into the canonical pending-allocation queue. This clears the physical shipment warehouse, carrier/service, staged tracking, review/stockout markers, and releases an active reservation; the transaction writes workflow/route revisions and audit, then verifies queue membership.
- Real dispatched shipment facts, archived orders, and A/B/C split fulfillment are explicitly blocked with per-row feedback. They cannot be silently erased by changing a label; this is an explicit safety boundary requiring further Owner product decision if they must be overridden.
- No new migration, external provider dispatch, Woo write, real-order mutation, Production runtime change, or release is authorized.

## R1–R4 implementation and validation

1. **R1 UI:** Domestic and Overseas pages share a selectable bulk footer on the six requested states, retaining existing Global Grid settings, permissions, page actions, and 30-tab Workspace defaults.
2. **R2 mutation:** Separate domestic and overseas POST routes dispatch to one transactional service. HTTP permissions, max 50 distinct IDs, per-row success/error, audit and canonical queue readback are enforced.
3. **R3 automated:** US Mac mini isolated worktree at `/Users/a/ai/uat/erp-bulk-return-20261120`; focused Laravel test 3 PASS / 64 assertions; logistics regression 105 PASS / 2,126 assertions; focused Vue 122 PASS across three files; `npm run typecheck` PASS; `npm run build` PASS, including `ERP_WORKSPACE_TABS_BUILD_PASS`.
4. **R4 real Local/UAT:** Isolated SQLite and local-only synthetic seeder (six `UAT-BATCH-*` orders), loopback server `127.0.0.1:18083`; approved temporary `admin` / `password` credential. All six real Global Grid pages have been opened, seeded row selected, the **批量到待分仓** option chosen, and Apply button confirmed visible. **Owner live-page UAT PASS is pending.**

## Delivery

- One feature branch: `feat/erp-six-grids-bulk-return-allocation-20261120`, based on current `main` (`e7b93bca` at implementation sync).
- The Orders/KOL mutation-contract workflow is extended to run the added backend/UI regression tests on the exact PR head.
- Draft PR may be opened for CI. **Do not merge before Owner live-page PASS**; Production requires a separate explicit authorization, exact candidate SHA, rollback and post-release readback.

## Acceptance readback

For every successful row, assert `workflow_status=pending_allocation`, `routing_warehouse_code=UNASSIGNED`, shipment `warehouse_id=NULL`, `carrier=NULL`, `service=NULL`, `tracking_number=NULL`, membership in `LogisticsStatusSummary::pendingAllocationQuery()`, and corresponding workflow, warehouse-route and audit records. Negative cases must preserve original warehouse and dispatch history.
