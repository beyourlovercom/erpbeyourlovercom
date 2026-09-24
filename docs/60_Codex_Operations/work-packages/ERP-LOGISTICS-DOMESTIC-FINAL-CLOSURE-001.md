# ERP-LOGISTICS-DOMESTIC-FINAL-CLOSURE-001

## Owner scope

Owner authorized completion and closure of the domestic logistics branch on 2026-09-24, including correction of the real Logistics level-2 menu.

## Frozen runtime menu

`待分仓 → 待发货 → 国内发货 → 海外仓发货 → 已发待单号 → 运输中 → 异常订单 → 承运商`

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
- real return evidence contract is implemented and conflict-safe;
- no real outbound/order-upload template exists in repository history or supplied file library as of 2026-09-24;
- therefore provider-specific outbound export remains intentionally fail-closed; no guessed provider file format is permitted;
- the generic domestic pending-batch XLSX may register ERP fulfillment but explicitly does not impersonate a Weship provider order template.

The missing Weship outbound template is an external provider-protocol dependency, not an unimplemented inferred contract. When a real template is supplied, it requires its own field-contract inspection before enabling provider-specific export.

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
