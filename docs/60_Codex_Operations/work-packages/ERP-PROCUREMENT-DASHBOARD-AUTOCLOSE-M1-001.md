# ERP-PROCUREMENT-DASHBOARD-AUTOCLOSE-M1-001

## Objective
Complete Purchase OS V1 M3 by surfacing procurement progress on the canonical Dashboard and automatically completing active Procurement Orders when their source Order reaches `shipped`.

## Base / Isolation
- Base: `origin/main@709188df42a13345e8b7bc49573ae4b11a64a2de`
- Branch: `feature/erp-procurement-dashboard-autoclose-m1-001`
- Worktree: `/Users/a/AI/007/worktrees/erp-procurement-dashboard-autoclose-m1-001`
- No Production mutation.
- One WP / one branch / one worktree / one PR.

## Frozen Scope
- Dashboard procurement progress for users with both Dashboard access and `procurement.view`.
- Active Procurement Orders auto-complete when source Order transitions to `shipped`.
- Auto-completion is idempotent and never changes `cancelled` Procurement Orders.
- Manual completion remains supported.
- Persist completion reason and completion timestamp.
- Desktop and 390px verification.

## Completion Contract
- Active statuses: `pending`, `purchasing`, `ordered`.
- Auto-complete trigger: source Order changes from a non-`shipped` state to `shipped` through an Eloquent save.
- Auto result: `status=completed`, `completion_reason=source_order_shipped`, set `completed_at` and `status_updated_at`.
- Already `completed`: no rewrite; preserve the existing reason/timestamp.
- `cancelled`: no rewrite.
- Manual `completed`: `completion_reason=manual` and `completed_at` is set.
- Manual transition away from `completed`: clear `completion_reason` and `completed_at`.
- Reopening the source shipment does not automatically reopen Procurement.

## Dashboard Contract
- Existing four Dashboard metrics remain unchanged.
- Procurement progress is not exposed unless the authenticated user can `procurement.view`.
- Procurement progress includes counts for all five statuses, active total, and recent Procurement Orders.
- Recent rows deep-link to canonical `/purchase/orders/{id}`.
- No new Dashboard permission is introduced.

## Exclusions
- No receiving or inventory writeback.
- No supplier/cost/payment workflow.
- No reverse auto-reopen.
- No new notification workflow.
- No courier API or tracking-state synchronization.

## Verification Evidence
- Procurement focused: 15 tests / 130 assertions / 0 failures.
- Dashboard + Procurement Show Vue: 2 files / 9 tests PASS.
- Typecheck: PASS.
- Production build: PASS.
- Procurement + Manual Fulfillment + Notification Center + AppShell + ModuleProvider: 597 assertions / 0 failures.
- ModuleProvider: 8/8 PASS.
- Fresh SQLite migration rehearsal: PASS; `completion_reason` and `completed_at` present.
- LocalUatSeeder rehearsal: Admin and Zhang present.
- `git diff --check`: PASS.

## Browser Evidence
- Isolated 18130 real fulfillment smoke: Dashboard active=1 / ordered=1 before shipment.
- Real `/logistics` `确认发货` action completed `UAT-WEB-001`.
- Procurement changed `ordered -> completed` with `completion_reason=source_order_shipped`.
- Dashboard changed active=0 / completed=1 after shipment.
- Dashboard and Procurement Detail passed 390px horizontal-overflow checks.
- 18129 Owner DB remained pre-shipment and was never mutated by smoke.

## Known Pre-existing Governance Drift
- Full `AuthorizationFoundationTest` currently expects obsolete role choices (`super_admin/admin/manager/operator`) while current main exposes the newer role presets; this is unrelated to M3.
- Full `ModuleArchitectureTest` frozen file/directory baselines lag already-merged KOL, Notification, Packaging and Procurement M1/M2 files.
- M3 initially introduced a new Application -> Infrastructure dependency; it was remediated before UAT by moving the Eloquent shipment observer/completion writer into Procurement Infrastructure.
- M3 does not add a new Application -> Infrastructure dependency.

## Owner UAT Gate
- 18129 Owner DB is prepared with `PO-20260905-0001` in `ordered` state and source `UAT-WEB-001` still `pending_shipment`.
- Dashboard baseline: active procurement 1, ordered 1.
- Shipment draft is prefilled; Owner only needs to use the real Logistics `确认发货` action.
- Expected result: Procurement becomes completed with source-order-shipped reason and Dashboard moves the count from active/ordered to completed.
- Do not commit, push, PR, merge, or deploy before explicit Owner `pass`.

## Owner UAT remediation — Carrier Master
- Owner identified that `/logistics/carriers` was incorrectly derived from historical `Shipment.carrier` strings and therefore exposed test/history values such as `UAT Carrier` instead of the governed carrier set.
- Owner also required a top-right `＋ 新增承运商` action.
- Remediation introduces persistent `logistics_carriers` Carrier Master authority, seeded with: `4PX`, `DHL`, `USPS`, `UPS`, `FedEx`, `Canada Post`, `SF Express`, `Singapore Post`.
- `/logistics/carriers` now lists Carrier Master rows even when shipment count is zero; shipment history contributes statistics only.
- Shipment-only carrier strings do not automatically become master data.
- `logistics.operate` may add a carrier by name; `logistics.view` remains read-only.
- A newly added active carrier immediately becomes an overseas-warehouse fulfillment carrier option.
- Carrier delete/rename/deactivate UI is intentionally out of this remediation to avoid changing historical Shipment linkage.
- WooCommerce/AfterShip technical slug mapping remains explicit; adding custom master data does not implicitly invent an external-provider mapping.
- Browser evidence: Owner 18129 showed all 8 initial masters, excluded `UAT Carrier`, showed the top-right add button, and passed 390px overflow check. Isolated 18130 successfully persisted and rendered `UniUni Smoke`; Owner DB remained at 8 rows.

## Owner UAT remediation — Carrier Master

Owner identified that `/logistics/carriers` was still projecting historical Shipment carrier text instead of the configured carrier authority, and requested a top-right add-carrier action. `/logistics/*` remains the frozen canonical URL contract; no `/ship/*` migration is in scope.

Implemented remediation:
- added canonical `logistics_carriers` master table and Carrier model;
- seeded 8 existing carriers: 4PX, DHL, USPS, UPS, FedEx, Canada Post, SF Express, Singapore Post;
- `/logistics/carriers` now renders Carrier Master rows, with Shipment history only contributing statistics;
- historical free-text values such as `UAT Carrier` do not become master data automatically;
- logistics operators receive a top-right `＋ 新增承运商` action;
- newly created active carriers immediately become `/logistics` fulfillment dropdown options;
- fulfillment draft/ship validation rejects carriers outside active Carrier Master;
- WooCommerce/AfterShip explicit provider mapping remains separately guarded.

Verification evidence:
- Logistics Carrier/Index Vue + Logistics Index Vue: 6/6 PASS;
- CarrierWorkspace + ManualFulfillment + WooCommerceTrackingOutbound + Procurement: 305 assertions / 0 FAIL (warning-only);
- typecheck PASS; production build PASS; `git diff --check` PASS;
- Owner DB contains exactly the 8 seeded carriers before Owner mutation;
- isolated 18130 browser smoke: add `UniUni Smoke` → persisted → immediately selectable in fulfillment dropdown PASS;
- 18129 Owner read-only browser: 8 canonical carriers, no historical `UAT Carrier`, top-right add action visible PASS.

## Owner UAT Final Gate

- 2026-09-05: Owner PASS confirmed for M3 dashboard procurement progress and source-order-shipped auto closure.
- Owner also PASSed the Logistics Carrier Master remediation: canonical eight carriers render at `/logistics/carriers`, top-right `＋ 新增承运商` is available to logistics operators, newly added active carriers become fulfillment dropdown options, and historical free-text shipment carrier values do not become master data.
- `/logistics/*` remains the frozen canonical Logistics URL contract; `/ship/*` migration is explicitly not part of this work package.
- Final delivery status: READY FOR COMMIT / PR / MERGE. Production remains untouched.
