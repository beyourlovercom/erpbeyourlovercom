# R8 M2 — OMS Tracking evidence and safe writeback gate

## Delivered in PR #552 (read-only)
- Exact OMS readback returns native `logisticsCarrier` as unverified `carrier_raw`, alongside native `logisticsTrackNo` and status. Bounded input validation rejects malformed carrier data.
- Dede's existing US-SM workbench can display the provider carrier string with an explicit “未核实，不自动回写” label. No changes to the existing shipment, Woo, AfterShip or original OMS order.
- New permissioned GET `/logistics/oversea/us-3sm/orders/{order}/xlwms/tracking-writeback-preview` requires exact OMS order and thirdOrderNo identity, code 3, nonempty tracking and carrier. Returns blockers and **always** `can_apply=false`, `requires_manual_carrier_verification=true`.
- Normal OMS code 0 draft, 5 exception, missing tracking or empty carrier never qualify. Even complete fields are candidates only; provider text alone is not independently verified last-mile carrier evidence.

## Remaining before M2 real writeback
1. Human Dede verification of genuine carrier and tracking against the provider label/warehouse shipment; persist verified actor/evidence and reject stale provider status.
2. Separate strict confirmation and idempotent ERP shipment update; source OMS order/thirdOrderNo/fingerprint must remain identical.
3. Reuse existing Vita WooCommerce / AfterShip workflow with verified status and carrier, retaining retryable outbox and no duplicate notifications.
4. Dev UAT, MySQL8/SQLite CI, approved single-order Production Canary, independent release and rollback.
5. No automatic Woo or AfterShip push from this read-only PR.
