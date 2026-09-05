# ERP-PURCHASE-OS-V1-CLOSURE-M4-001

## Contract

TITLE: Purchase OS V1 — Final Regression / Owner UAT / Closure
OWNER_AUTHORIZATION: Owner explicitly entered P-V1-M4 on 2026-09-05.
BASE: origin/main@8b848e4422029332438b292f412e42f932cf6e2a
BRANCH: feature/erp-purchase-os-v1-closure-m4-001
SCOPE_MODE: verification / UAT / closure only

## Frozen V1 chain

1. stockout Order exposes Create Procurement Order.
2. Procurement Order preserves exact source Order/Item truth.
3. Zhang receives persistent Global Notification and can operate Procurement.
4. Procurement supports five manual statuses plus carrier/tracking capture.
5. Tracking jump copies tracking number and opens kuaidi100.com with no API fee.
6. Dashboard projects procurement progress.
7. Source Order shipped auto-completes active Procurement, preserving cancelled.
8. Logistics uses canonical /logistics/* routes and Carrier Master.
9. Carrier Master owns active fulfillment carrier options and supports add carrier.
10. Legacy /orders/procurement remains the external-warehouse Order slice.
## Closure gates

- focused Vue regression: Procurement / Dashboard / Logistics / Carrier Master.
- PHP regression: Procurement, Notification Center, Fulfillment, Woo tracking, navigation, permissions.
- typecheck and production build.
- fresh migration + explicit LocalUatSeeder.
- browser E2E on isolated SQLite clone.
- Desktop and 390px no ordinary horizontal overflow.
- Owner final live-page UAT before delivery merge.
- no Production deploy or live-data mutation.

## Change policy

M4 SHALL NOT expand V1. A defect found by closure may receive a bounded compatibility fix in this same WP only when it is required to satisfy the already-merged V1 contract. New business capability requires a separate WP.

## Delivery rule

One Work Package / one branch / one worktree / one PR. Owner PASS is required before Git delivery merge. Post-merge repository mutation is not permitted under this WP identity.
## Verification evidence — 2026-09-05

Frontend closure regression: 7 Vue files / 20 tests PASS. Typecheck PASS. Production build PASS. `git diff --check` PASS.

Permanent `PurchaseOsV1ClosureTest`: PASS, 40 assertions. It locks stockout → Procurement creation → Zhang notification → read/deep-link → ordered carrier/tracking → Dashboard active projection → ShipmentService shipped → Procurement auto-completed → Dashboard completed projection.

Broad relevant backend regression: 1368 assertions. Purchase OS, Procurement, Notification Center, Manual Fulfillment, Order Fulfillment, Woo tracking, Carrier Workspace, navigation, AppShell, Local UAT credentials, User Management, Permission Matrix and ModuleProvider had no business failure. ModuleProvider 8/8 PASS.

Known governance drift was reproduced on a detached clean `origin/main@8b848e4422029332438b292f412e42f932cf6e2a`: AuthorizationFoundation has one stale old-role-choice expectation; ModuleArchitecture has three stale frozen directory/file-set baselines. These four failures are PRE-EXISTING_BASELINE_DRIFT and are outside M4 runtime scope.
Fresh migration rehearsal: PASS with explicit LocalUatSeeder. Admin/Zhang credentials PASS; Zhang has `procurement.view` + `procurement.operate`; Carrier Master count 8; completion audit columns present; initial Procurement count 0.

Isolated 18130 browser E2E: PASS after correcting test-harness clipboard permission and exact Dashboard selector. Verified Procurement creation, purchase logistics, kuaidi100 jump/copy, search/filter, Dashboard before shipment, Zhang unread→read notification and canonical deep-link, Carrier Master add→fulfillment dropdown, real Logistics shipment, automatic Procurement completion, final Dashboard counters, and 390px core pages.

18129 Owner DB remains pristine for final UAT: source Order `UAT-WEB-001` pending shipment, stockout marker present, Procurement count 0, Notification count 0, Carrier Master count 8. Tailscale read-only browser readiness PASS.

STATUS: READY FOR OWNER FINAL UAT. No runtime source change exists in M4; deliverable changes are this closure evidence and the permanent closure regression test only.
## Owner UAT refinement — 2026-09-05
- Owner reported Procurement detail hid the real purchase URL behind `打开采购链接`. Fixed Show UI to render the full persisted URL as the clickable `_blank` link, with wrapping for long URLs and the full URL retained as title text.
- Owner reported shipment could not proceed while warehouse recommendation was blocked. Root cause: active warehouses existed, but the UI silently disabled `确认发货` whenever required fulfillment fields were missing. The recommendation is advisory and must not imply manual fulfillment is forbidden.
- Logistics UI now keeps manual active-warehouse selection available when recommendation is blocked, explains that manual selection is allowed, keeps `确认发货` actionable, and returns explicit inline validation beginning with `请先选择发货仓库` instead of silently disabling the action.
- No inventory authority, recommendation algorithm, shipment service, schema, or Production data was changed.
- Focused frontend verification: Procurement Show + Logistics Index = 11/11 PASS; vue-tsc PASS; Vite production build PASS; git diff --check PASS.

## Final Owner UAT PASS — 2026-09-05

Owner completed the final Purchase OS V1 end-to-end UAT and reported `pass 全通过`.

Final UAT refinements accepted:
- Procurement detail displays the full purchase URL as the clickable link text instead of `打开采购链接`; long URLs wrap without breaking the card layout.
- Logistics blocked warehouse recommendation remains advisory only; operators can still select any active warehouse manually.
- `确认发货` no longer fails silently when fulfillment inputs are incomplete; it reports the first missing required field, including `请先选择发货仓库`.
- No automatic warehouse selection was introduced, preventing accidental shipment from the wrong warehouse.

Final Owner evidence on 18129:
- `UAT-WEB-001` reached `shipped` through the real fulfillment path.
- `PO-20260905-0001` reached `completed` automatically.
- `completion_reason = source_order_shipped`.
- Final procurement carrier/tracking remained `顺丰速运 / SF-M4-UAT-001`.

Owner verdict: PASS. Purchase OS V1 is approved for M4 delivery closure.
