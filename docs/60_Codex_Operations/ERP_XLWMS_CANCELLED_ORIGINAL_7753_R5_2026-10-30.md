# XLWMS R5 — cancelled original OMS / pending ERP reship request

## Owner-approved scope (2026-10-30)
- ERP 7753 (`007-1027-c41863`), US-SM, SKU CL279B x2.
- Original native OMS `OBS0272610290RS` was manually intercepted by warehouse; **do not alter, resubmit, or delete it**.
- ERP order is `pending_shipment` with `pending` Shipment and no original Tracking.
- Read-only original OMS lookup returned **status 4 (cancelled)** and native identity `BYL-ERP-7753`. Do not trust cached status 5 for authorization.
- Owner approves an independent reship **application**; independent OMS CREATE remains disabled until its own explicit one-order + request UUID approval.

## Engineering gate
- Only pending ERP + pending US-SM shipment + no original tracking + linked native OMS + fresh exact provider status 4 + exact native third order reference can follow the cancelled-original route.
- All normal shipped-order restrictions are preserved. Live OMS lookup failure/mismatch fails closed. Inventory and SKU conditions still block.
- New request writes only `xlwms_reshipments` and its audit events, not original ERP shipment, Woo, Tracking or provider OMS.
- Create requires a separate new UUID allowlist and inventory freshness; do not make the original ERP appear shipped to pass preflight.

## Evidence
- Focused backend tests: 65 tests, 332 assertions PASS; Vue tests 29 PASS; typecheck PASS.
- Follow-up production checks must verify original OMS live status 4 and original ERP readback unchanged.
- The previous two original OMS orders remain protected. This request is explicitly scoped to ERP 7753; it does not authorize modifying original OMS `OBS0272610290RS` or using any other order.
