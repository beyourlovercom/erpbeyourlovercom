# ERP Global Grid V1.2 single-row footer batch actions, 2026-11-10

Owner instruction: eliminate the duplicate header subtitle "共128条，已选3条" under "国内发货 · 待审核". The table header has one title only and unchanged search/columns/density/maximize tools. NO extra batch-action row.

## Scope reduction - latest Owner decision (2026-11-10)

The original 23-46h plan is SUPERSEDED. U4 logistics-exclusion is CANCELLED and must not appear in any batch dropdown or implementation. Archive reuses existing authorized single-order archive semantics, and finance is limited to two active report query filters. Current MVP roadmap and estimate: [ERP_GLOBAL_GRID_V12_FAST_MINIMUM_20261110.md](ERP_GLOBAL_GRID_V12_FAST_MINIMUM_20261110.md), **7-13h** incremental work. Earlier details retained only as historical reference.

## Frozen UX requirements
- The ONE existing bottom pagination/footer row contains all selection and bulk controls. No toolbar above the table, below the table, or duplicated in the title.
- When zero orders selected: hide selected count, bulk-action dropdown, inline reason, Apply. Still show original pager, per-page and total on right.
- When N>=1 selected: show "已选 N 条" on left, followed by "批量操作" dropdown. Only after choosing a permitted action show the single "应用" button immediately to its right.
- For batch reject needing a reason, show compact inline reason input WITHIN SAME footer row, no popup and no invented default reason. Apply disabled until the original reason rule is satisfied. Original backend permissions and audit remain mandatory.
- Pinned LEFT: checkbox select by authorized ERP order ID, select-all current server page only. Do not imply selecting unseen server results.
- Pinned RIGHT: per-row domain buttons "通过 / 缺货 / 驳回" and other page-specific actions, without "操作此单" expanding another native table or navigation. Inline reject reason; original server-side state, payment and permission gates.
- Stockout list has only "已到货-可发货"; per-list action matrix controls the visible buttons. Other critical carrier/tracking/export/import controls must remain functional.
- No bulk writes in a design-only review. Backend validates all selected orders individually, enforces existing KOL/warehouse permissions, prevents stale version or duplicate OMS dispatch, audits outcomes and reports partial failures. No automatic retry of successful items.

## Distinct bulk business outcomes
- Bulk Archive: archived order exits all active order/logistics/finance worklists and operating KPIs, retains authorized archive/audit/history and immutable posted financial records.
- Bulk Exclude Logistics: order stays valid and in normal FINANCE calculations, but leaves all allocation, review, shipping, transport, tracking, carrier exports and OMS dispatch/retries. Separate server-side exclusion flag and all-workflow gate are required.
- If already shipped or warehouse/OMS accepted, do not claim visual exclusion cancels external shipping; block or require an existing independently authorized cancellation and acknowledgement.
- Both operations require explicit backend Dev/UAT before any Production enablement.

## Updated incremental roadmap and labor

| Phase | Work | Engineering + verification |
|---|---|---:|
| U1 | Remove redundant title statistics, use only existing bottom row, conditional selected count / dropdown / Apply, responsive layout | 3–6 h |
| U2 | Shared pinned-left selection, pinned-right direct operation matrix, inline rejection reason, permission visibility | 2–4 h |
| U3 | Server bulk archive, per-order authorization/idempotency/audit, restore, active-workflow/finance-followup removal | 4–8 h |
| U4 | Separate logistics exclusion state, all dispatch/queue/OMS/Tracking/export guards, restore and external-state blocks | 8–16 h |
| U5 | Finance: logistics-excluded IN accounting; operationally archived OUT of active finance worklists, historical ledger intact | 2–4 h |
| U6 | Exact-head tests, role/warehouse regression, browser Owner UAT, controlled-release and rollback gate | 4–8 h |
| TOTAL NEW CAPABILITY | Conditional provisional estimate, not actual work logged | 23–46 h |

U1+U2 first as domestic Dev canary (5–10 h), Owner review before U3+U4/U5. This incremental 23–46h is not a second charge for existing Global Grid V1 controls and is separate from the conditional 40–80h 51-page display migration plan; share common testing and do not double count. Measure actual hours after first canary.

## Domestic Owner acceptance
- Header subtitle absent. No added toolbar. Same original bottom footer row whether selected or not.
- 0 selected: no "已选" and no bulk dropdown/Apply. 1+ selected: count and dropdown visible; Apply shown only after a specific action is picked. For mandatory-reason action inline input is within footer.
- No added vertical row or popup. Header/columns and paging stable across horizontal scroll and 20/50/100 server pagination.
- Immediate single-row approve, stockout and reject buttons stay pinned RIGHT. Source order identity and all permissions preserved.
- Individual partial failures surfaced; no double shipment, no archive of unacknowledged external shipments.
- Production default switch and new bulk writes require separate Owner acceptance and authorization.
