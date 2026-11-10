# ERP Global Grid V1.2 - Minimum viable operations; revised estimate, 2026-11-10

Owner explicitly cancelled U4 logistics-only exclusion, reduced U3 to existing operational archive reuse, and cut finance work. This supersedes the previous 23-46h V1.2 enhancement plan. The previously approved one-row bottom-footer UX remains unchanged except that the logistics exclusion option is REMOVED.

## Frozen MVP implementation

- NO duplicated subtitle with count or selected rows under the table title and NO extra toolbar. The existing bottom pager row contains the conditional selected count, bulk action dropdown, inline reason if needed, and Apply.
- 0 checked: hide selected count, dropdown, reason and Apply. 1+ checked: show selected count and permitted dropdown; display Apply only after selecting a permissible action.
- Checkbox pinned LEFT, domain-specific direct approve/stockout/reject buttons pinned RIGHT. Reject mandatory reason entered inline, no second table/no modal. Reuse GlobalGrid/OmsGrid and existing review endpoints/permission gates.
- Bulk actions are limited to existing-domain approve, stockout, reject and archive. **No bulk-exclude-logistics or logistics-exclusion state, schema, OMS, tracking or dispatch changes.**
- Archive: reuse OrderOperationalArchiveController::archive semantics and archive/restore authorization, KOL visibility, database locking, audit and parent-child safeguards. The existing archive endpoint accepts ONE order only; add a very thin bounded batch orchestrator (or safe equivalent) for one Apply action. Re-query each order and return separate success/failure, avoid duplicate execution, and do not silently cancel already-dispatched external shipments.
- Finance MINIMUM: edit ONLY existing active OrderAnalysisController::filteredOrders and ProfitDashboardController::index query filters so operationally archived orders and descendants of archived parents are excluded from daily financial worklists/metrics. CSV using the same query inherits the filter. Do not change formulas, FX, payments, ledger, historical reports, other finance modules or posted records.
- No Production writes or enablement until Owner visual UAT, necessary permissions/flow regression and explicit release authorization.

## Fast-path roadmap / incremental work

| Phase | Work | Estimated hours |
|---|---|---:|
| M1 | Existing footer conditional selected count, dropdown and Apply; remove duplicate title statistics and added toolbar | 1-2 |
| M2 | Left selection/right fixed domain actions, inline rejection reason, reuse approved existing review handlers | 1-2 |
| M3 | Bounded batch calls reusing existing archive and review gate, partial-result reporting; no new archive state | 2-4 |
| M4 | Minimal archive filters for TWO existing finance entrypoints and focused tests | 1-2 |
| M5 | Exact-head PHP/Vue/Chrome regression, Owner UAT, controlled-release and rollback gate | 2-3 |
| TOTAL | Only NEW MVP capability; not all 51 grid conversions | 7-13 |

Fastest plausible completion is about 7-9 working hours (approximately one normal workday), conditional on no unforeseen conflicts and prompt Owner UAT. This is an estimate, not a guaranteed asynchronous completion time. Existing L1 PR #679 is a Dev draft; no claim that M1-M5 are already implemented. If archive batch semantics cannot be safely reused, report the blocker rather than weakening the backend rules.

The original 51 selected Global Grid display routes remain under their own logistics-first plan; do not double count already-built Grid selection and column infrastructure. Existing immutable posted financial history remains true even when archived orders disappear from active financial worklists.
