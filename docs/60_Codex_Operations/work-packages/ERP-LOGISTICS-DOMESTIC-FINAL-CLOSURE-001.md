# ERP-LOGISTICS-DOMESTIC-FINAL-CLOSURE-001

## Owner scope

Owner authorized completion and closure of the domestic logistics branch on 2026-09-24, including correction of the real Logistics level-2 menu.

## Frozen runtime menu

`待分仓 → 国内发货 → 海外仓发货 → 已发待单号 → 运输中 → 异常订单 → 承运商`

`国内发货` canonical route: `/logistics/china` / `logistics.china` / permission `logistics.view`.

## Domestic workflow

`Vita 分仓 → Liang/Kaven 国内审核 → Provider 文件 / ERP 批量登记 → Tracking Return Preview → Apply → canonical Shipment → Woo outbound when applicable`.

### 4PX / 速达

- real 24-column outbound contract: implemented;
- required Phone and canonical Email: enforced;
- valid rows continue when another row is blocked;
- XLS/XLSX Tracking return Preview / partial Apply: implemented;
- canonical Shipment remains the fulfillment truth.

### Weship / 纬狮

- Owner routing remains MX / CL → Weship;
- Owner confirmed on 2026-09-24 that Weship outbound uses the same 24-column order-upload template contract as 4PX / 速达;
- the physical workbook remains the frozen 4PX 24-column template; ERP fills the same green/canonical fields for both providers;
- provider batches remain isolated: 4PX export contains only pending reviewed 4PX shipments, while Weship export contains only pending reviewed Weship shipments;
- Weship download filename is `007-weship-orders-YYMMDD.xlsx`; 4PX remains `007-4px-orders-YYMMDD.xlsx`;
- required Phone / canonical Email / address / quantity / IOSS rules and per-order blocker isolation are shared across both providers;
- real return evidence contract remains provider-specific and conflict-safe;
- no guessed provider schema remains: the shared 24-column contract is now an explicit Owner-frozen business rule.

## Closure gates

1. Runtime + approved Logistics level-2 menus match exactly.
2. Domestic production closure tests PASS with PhpSpreadsheet installed from canonical composer.lock.
3. App Shell / Approved Menu tests PASS.
4. Domestic UI tests / TypeScript / production build PASS.
5. Local/UAT real `/logistics/china` page and real left navigation prepared for Owner inspection.
6. Production release remains a separate explicit Owner gate.

## Validation status

- DomesticLogisticsProductionClosureTest locks the formal Logistics menu and route ownership; implemented Domestic/Oversea/Carrier paths are intentionally excluded from the legacy prototype menu to prevent route shadowing.
- AppShell focused Logistics navigation: 1 test / 58 assertions PASS.
- Domestic + AuthenticatedLayout Vitest: 15 tests PASS.
- Vue TypeScript: PASS.
- Vite production build: PASS.
- `git diff --check`: PASS.
- Full AppShell inherits one exact-main `prototype.view` → `/logistics/pending` 403 assertion failure; domestic branch adds no failure.
- Approved Menu Logistics entries now match runtime exactly. The full suite still exposes inherited non-Domestic governance debt: Accounting loss-order slug, Accounting A8 stale child expectation, and Marketing Traffic runtime/baseline drift; these are outside this closure scope.
- ModuleArchitecture inherits the exact-main frozen module/layer/migration baseline drift; the changed menu SHA invariant is updated and does not add a failure.

Final close still requires Owner inspection of the real Local/UAT `/logistics/china` page and left navigation. Production deployment is a separate hard gate.

- DomesticLogisticsProductionClosureTest: 6 tests / 72 assertions PASS after route-shadowing remediation.

## Retired standalone pending workspace

Owner confirmed on 2026-09-24 that the standalone `待发货` workspace is historical and superseded by `待分仓`, `国内发货`, and `海外仓发货`. `待发货` remains a valid order/shipment status label inside the domestic/oversea workflows, but it is no longer a standalone Logistics page. `/logistics` and `/logistics/pending` are compatibility redirects to `/logistics/wait-stock`; formal navigation, Dashboard actions and status bars must not link to the retired page. The retired Vue page and unit test are deleted.

## 2026-09-24 standalone Pending workspace retirement

Owner confirmed the standalone `待发货` page is historical. Final formal Logistics level-2 navigation is now:

`待分仓 → 国内发货 → 海外仓发货 → 已发待单号 → 运输中 → 异常订单 → 承运商`

Closure evidence after retirement:

- retired Vue surface `resources/js/Pages/Logistics/Index.vue` and its unit test are deleted;
- `/logistics` and `/logistics/pending` are permission-gated compatibility redirects to `/logistics/wait-stock`;
- unauthorized users still receive 403 on the legacy URL;
- Dashboard and Logistics status bars no longer link to the retired page;
- `待发货` remains a business status label inside domestic/oversea workflows;
- DomesticLogisticsProductionClosureTest: 6 tests / 80 assertions PASS;
- FulfillmentAllocationV1Test: 6 tests / 55 assertions PASS;
- LogisticsOsV1ClosureTest: 37 tests / 361 assertions PASS (1 MySQL-only skip);
- OverseaWarehouseWorkbenchTest: 32 tests / 329 assertions PASS;
- OrderFulfillmentM1Test: 17 tests / 209 assertions PASS;
- AppShellNavigationTest: 20 tests / 348 assertions PASS;
- ERP Navigation: 11 tests / 146 assertions PASS;
- PageHeaderActionStandardTest: 4 tests / 15 assertions PASS;
- wait-stock Production query budget at 50 rows: PASS at canonical <=100 queries after request-local carrier/service caching and batched active-reservation preload;
- exact-main inherited ManualFulfillment adopt-recommendation failures remain 7/7 identical and are outside this closure scope;
- Approved Menu inherited failures remain Accounting A8 / accounting-loss-orders and Marketing Traffic baseline drift only;
- ModuleArchitecture inherited frozen module/layer/migration baseline drift remains outside this closure scope.

## 2026-09-24 Weship shared outbound-template freeze

Owner explicitly confirmed that the 4PX / 速达 outbound template is also the canonical Weship outbound template. Domestic Closure therefore treats both providers as `export_ready=true`, while preserving provider-isolated order selection and filenames. Regression coverage verifies a mixed database state with both carriers exports exactly one Weship row through `/logistics/china/export/weship`, keeps the 24-column contract, and leaves Shipment state unchanged.
