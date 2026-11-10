# MorePlus admin operator visibility / safe-paused execution (2026-11-10)

## Facts
- Source main: e73c2cca, prior PR #672 running in Production. Existing role_preset_administrator ID1 has all 49 registered permissions and is authorized as one of three exact ShipOut V1 operator IDs. No global super_admin override or mutation of other roles.
- Production R8/R9 and dedicated shipout-production-batch worker remain deployed, while actual batch submission flags remain OFF.
- C42199 / ERP7960 was submitted only once in real batch, with signed OMS status6 PROVIDER_DUPLICATE_ORDER; immutable ledger one attempt, item provider_cancelled and batch completed_partial.
- Signed fresh account-wide and warehouse historical OMS GET both returned 19 records; excluding C42199, none of 18 visible historical records had matching SKU BX033B, ZIP, address or recipient. Warehouse internal duplicate rules are not provably equivalent to the public 14-day history query. Do not misstate content-clear as provider-acceptance proof.
- C42149/ERP7913 confirmed duplicate and C42141/ERP7903 EzeeShip status6 must not auto POST again. C42203/ERP7963 OMS status3 but not physically shipped. Production batch remains OFF pending verified provider duplicate-key/source explanation.
- Change restores exact-authorized administrator READ-ONLY batch preview and prior batch status while POST gate OFF, with 403 for start. Shows an explicit Chinese pause notice and disables confirm button. Users outside exact V1 operator list do not see this Production restricted batch surface.
- Permission regression checks administrator preset includes every registered ERP action permission. Payment, stock, review, immutable ledger, content-risk and global feature safety gates cannot be bypassed by admin. Role templates remain editable; no permissions DB changes.

## Administrator catalog drift correction
- Additional read-only Production check found that the installed Administrator role grants 49 permission rows, but RolePreset::Administrator now declares 50. The missing registered catalog entry is products.parameters.submit (product parameter submission), so the existing administrator was indeed missing a business button permission.
- New additive migration 2026_11_10_150000_backfill_administrator_preset_permissions restores ALL existing role-preset Administrator permissions without dropping any prior grants. Admin role users: 2 at read-only inspection. No user-specific custom ACLs or other role grants change.
- The migration is safe to rerun with insertOrIgnore; down is intentionally non-destructive to protect access to the last system-permission administrator. Verified in SQLite test for full admin catalog, idempotence, unchanged staff/custom ACL.
- This is a permissions-only role grant, NOT a permission to override business payment, human approval, provider dedupe, dispatch ledgers, or the Production batch kill switch.
