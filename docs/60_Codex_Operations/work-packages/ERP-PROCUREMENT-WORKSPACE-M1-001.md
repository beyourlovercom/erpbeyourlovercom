# ERP-PROCUREMENT-WORKSPACE-M1-001

## STATUS

- Work Package: P-V1-M2 Procurement Workspace
- Base: `main@c096de6ee5bf4160baf9bb641d956cf72d8565e9`
- Branch: `feature/erp-procurement-workspace-m1-001`
- Owner execution authorization: GRANTED 2026-09-04
- Owner live-page UAT: REQUIRED before delivery/merge
- Production: FORBIDDEN

## FROZEN SCOPE

1. Turn the existing `/purchase` list/detail into the daily procurement workspace.
2. Add manual procurement status editing.
3. Notify procurement operator Zhang when a new procurement order is created.
4. Add purchase logistics capture: carrier + tracking number.
5. Reuse Global Notification Center; no module-local notification system.
6. Preserve the existing Order → Procurement bridge and `/purchase` URL contract.
7. Preserve legacy `/orders/procurement` external-warehouse semantics.

## EXPLICIT EXCLUSIONS

- No Supplier Master, cost, currency, payment, receiving, inventory mutation, ETA, external tracking API, profitability or approval flow.
- No Dashboard procurement widget in this WP.
- No source-Order-shipped auto-completion in this WP; that is P-V1-M3.
- No Production migration or real-data mutation.

## DATA CONTRACT

`procurement_orders` adds:
- `carrier` nullable string(128)
- `tracking_number` nullable string(128)
- `updated_by` nullable user reference
- `status_updated_at` nullable timestamp

Status values remain exactly:
- `pending` = 待采购
- `purchasing` = 采购中
- `ordered` = 已下单
- `completed` = 已完成
- `cancelled` = 已取消

Any user with `procurement.operate` may manually set one of these values and edit carrier/tracking.
No automatic transition is introduced in M2.

## NOTIFICATION CONTRACT

- Add `procurement.operate` permission.
- New procurement creation publishes a persistent notification to active users with `procurement.operate`, excluding the creator.
- UAT dataset includes a dedicated ERP user named `Zhang` with procurement view/operate permissions.
- Notification action URL is `/purchase/orders/{id}`.
- Notification related links include the procurement order and source Order.

## UI CONTRACT

### `/purchase` and `/purchase/orders`
- Search by procurement number, source order number, SKU or product summary.
- Filter by the five frozen procurement statuses.
- Show procurement number, source order, status, purchase-line summary, logistics summary and last update.
- Desktop table; 390px card layout; no ordinary horizontal overflow.

### `/purchase/orders/{id}`
- Preserve source order and purchase-line read-only evidence.
- If user has `procurement.operate`, show editable status, carrier and tracking number.
- If user only has `procurement.view`, show the same facts read-only.
- One save action updates status/logistics together.

## ACCEPTANCE

1. View-only user cannot PATCH procurement state/logistics.
2. Procurement operator can set each of the five statuses.
3. Carrier and tracking number persist and render on detail/list.
4. Invalid status is rejected server-side.
5. New procurement creation sends a Notification Center record to Zhang in UAT.
6. Notification links to the canonical `/purchase/orders/{id}` route.
7. Existing duplicate-prevention and source-order bridge remain green.
8. Desktop and 390px workspace pass browser UAT.
9. Fresh migration rehearsal passes on isolated SQLite.
10. Production remains untouched.

## PRE-UAT VERIFICATION EVIDENCE

- Focused Procurement Vue tests: 3 files / 6 tests PASS.
- Typecheck: PASS.
- Production build: PASS; 889 modules transformed.
- Procurement + Local UAT backend tests: PASS; 98 assertions after final login remediation.
- Cross-module regression before login remediation: PASS; 961 assertions, warning-only.
- Factory packaging-only login regression after remediation: PASS; 5 assertions.
- `git diff --check`: PASS.
- PHP lint: PASS.
- `public/hot`: absent.
- Fresh isolated SQLite migration + explicit LocalUatSeeder rehearsal: PASS.
- Zhang UAT user has `procurement.view + procurement.operate` and no `procurement.create`.

### Browser smoke on isolated 18130 clone

- Admin stockout Order → Procurement create: PASS.
- Generated `PO-20260904-0001`: PASS.
- Manual `ordered` status + carrier + tracking persistence: PASS.
- Search + status filter + logistics summary: PASS.
- Desktop and 390px list/detail: PASS; no ordinary horizontal overflow.
- Zhang persistent notification: PASS; exactly one unread notification after creation.
- Opening notification marks it read immediately: PASS.
- Notification deep-link to `/purchase/orders/1`: PASS.
- Zhang has procurement operate controls: PASS.
- UAT defect found and fixed: procurement-only user authenticated successfully but default `/dashboard` redirect returned 403. `RoleAwareLoginResponse` now sends non-dashboard procurement viewers to `/purchase`; packaging-only routing remains unchanged.

Current state: READY FOR OWNER LIVE-PAGE UAT. No commit/push/merge before Owner `pass`.

## OWNER UAT CLOSURE

- Owner live-page UAT: PASS 2026-09-05.
- Final Owner micro-adjustment: carrier changed from free-text input to dropdown of common domestic couriers.
- Default courier options: 顺丰速运、京东物流、中通快递、圆通速递、申通快递、韵达快递、极兔速递、德邦快递、邮政 EMS、中国邮政.
- Backend storage remains a string; no database enum was introduced.
- Existing/legacy carrier values remain selectable so historical data is not lost.
- Live 18129 verification confirmed the dropdown and legacy-value compatibility.
