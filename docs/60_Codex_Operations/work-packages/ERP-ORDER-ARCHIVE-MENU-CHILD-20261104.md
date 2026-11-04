# ERP order archive: visible navigation, derived fulfillment and no purge

Owner authorization: 2026-11-04 after PR #615 reached Production.

## Frozen scope
- Add one static Orders level-2 menu entry: 归档清单 (4th child after 外采订单), using existing /orders/archive route and orders.view permission. Synchronize runtime nav and menu baseline, no new sidebar feature.
- All active orders including derived fulfillment children can be archived independently. Child archive does not modify parent, siblings or original allocation/shipment history.
- Parent archive continues excluding derived children from operations; inherited children also appear in archive list with a clear origin label, and are restored via parent. Independently archived children can be restored individually; restoring parent never silently restores independently archived children.
- No permanent order deletion: remove Trash purge button and backend purge always returns HTTP 409 for permitted administrator, preserving old soft-delete/restore functions. No irreversible data migrations.
- Existing access controls are preserved: orders.view for list, orders.edit for archive and system.permissions.manage for restore; KOL-only users limited to their own-authority order records.
- Test isolated DB and browser UAT, then GitHub PR and controlled Production release. Preserve production WMS/XLWMS overlays.

## Safety checks
- No real order archive/delete operations in testing or release.
- Confirm child archive is isolated and transaction/audit record remains canonical.
- Confirm menu visibility and direct route permissions.

## Actual UAT evidence
- Isolated US Mac SQLite UAT at 127.0.0.1:18866; four synthetic orders only.
- Browser checks PASS: menu visibility and fourth placement; child button enabled; independently archive child; parent unaffected; restore child; archive parent and list inherited child; inherited child may explicitly archive itself; restore parent leaves independently archived child; restore child; no permanent-delete button; mobile no overflow.
- Orders regression: 76 tests, 990 assertions PASS.
- Shell navigation regression: 23 tests, 411 assertions PASS.
- TypeScript typecheck and Vite build: PASS.
- ApprovedMenuBaselineTest current: 6 tests, 2 failures. Pristine origin/main baseline: 5 tests, 3 failures (preexisting accounting-loss-orders slug inconsistency and runtime/baseline drift). The new archive baseline assertion passes; no new failures; no unrelated menu modifications.
- Production database changes: NONE.
