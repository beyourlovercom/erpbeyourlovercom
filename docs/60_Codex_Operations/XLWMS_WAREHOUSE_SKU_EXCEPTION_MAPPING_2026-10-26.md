# ERP → 世迈 OMS exceptional SKU mapping — backend prepared, Owner UI approval pending

## Real evidence
C41704-007 / ERP 7679 has VP757A x1 while existing already-shipped OMS record OBS0272610230RS has VP757B x1. This is a candidate identity exception, NOT proof of physical equivalence. NEVER recreate that order or mark a product mismatch resolved by a candidate mapping.

## Fulfillment data contract
- ERP order, product and Woo SKU are canonical and immutable through this mapper.
- The OMS SKU is identical by default. Only explicit exceptions scoped by warehouse+provider+ERP SKU translate outbound payload, not original order records or warehouse stock.
- Mapping proposed status is draft; draft or disabled mapping BLOCKS related outbound. Active requires exact admin confirmation phrase, physical model/barcode evidence, reviewer and timestamp and read-only OMS catalog approved SKU check.
- No carrier channel remapping.
- Preview includes erp_sku, oms_sku and mapping status. Outbound request hash includes OMS SKU and invalidates on mapping changes.
- Original config-only XLWMS mapping readiness service remains legacy; live SingleOrderPreview reads a DB-scoped authoritative resolver instead of config mapping.

## Implemented without UI changes
- Database warehouse_sku_mappings, warehouse/provider/source unique constraint, active-only resolver.
- Settings/warehouses scoped admin-only API for list/propose/confirm/disable.
- Audit records for proposal, confirmation and disable, and defensive confirmation checks.
- New tests confirm fail-closed behavior, no warehouse leakage, no alteration of ERP SKU, no outbound request during mapping administration.
- Real dispatch remains OFF.
- Existing WarehouseShow.vue and credential OpenAPI panel remain unchanged. Required Owner design-preview PASS before adding visual mapping editor.

## Verification
- Isolated US Mac mini worktree with worktree-local Composer autoloader. 77 PHP tests / 581 assertions PASS including overseas warehouse module, TypeScript and Vite build PASS; code lints PASS.
- Github Actions #501/#504 fail before job starts on account payments / spending limit, not code execution.
- No production migration, mapping activation, actual OMS API outbound or Woo writes. Draft child PR targets #504.
