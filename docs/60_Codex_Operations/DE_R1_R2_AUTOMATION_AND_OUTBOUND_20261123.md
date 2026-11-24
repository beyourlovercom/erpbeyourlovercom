# DE/Hannover R1–R2 deployment note (2026-11-23)

## R1 — OMS-authoritative physical inventory
- Command: xlwms:sync-de-physical --dry-run : signed read-only OMS stock observation; no ERP inventory write.
- Command: xlwms:sync-de-physical --apply : only DE warehouse QOH, provider available and provider-stock freshness fields.
- Every changed SKU gets an inventory_adjustments record and system audit entry.
- Existing order reservations, shipments, FIFO lots, storage aging, and other warehouses remain unchanged.
- Exclusions zuodian and X002DEC9SZ are honored before product identity verification.
- Incomplete provider pagination, unverified identity, duplicate product mapping and invalid quantity block the whole apply transaction.
- Applied physical proof is separate from historical V2 lot ledger reconciliation; an older ledger block remains visible.
- Separate default OFF flag XLWMS_DE_AUTHORITATIVE_AUTO_APPLY_ENABLED. The existing default OFF XLWMS_DE_INVENTORY_SYNC_SCHEDULE_ENABLED triggers every 30 min. Both must be enabled to auto apply.
- Shared cache lock plus row locks prevent multiple concurrent writes.
- Production first apply: backup, real signed read-only dry-run, compare per SKU, enable guarded apply and read back, verify audit rows and unchanged reservations.

## R2 — DE selected-order production outbound
- Existing selected-order preview/confirm/status UI is preserved.
- Default OFF production read UI flag XLWMS_DE_ORDER_BATCH_READ_ENABLED; independent default OFF real-creation flag XLWMS_DE_ORDER_BATCH_LIVE_ENABLED.
- Real create additionally requires explicit nonempty XLWMS_DE_ORDER_BATCH_LIVE_ORDER_IDS allowlist and verified native XLWMS_DE_VERIFIED_CHANNEL_CODES.
- Dev simulation never performs provider HTTP writes.
- Production live uses DE/Hannover only, an approved pending-shipment order, verified Product OS identity, fresh authoritative physical proof, adequate provider available quantity and exact channel.
- Before CREATE: durable attempt unique ledger, signed duplicate lookup with exact SKU quantities and provider channel/product catalogue check.
- CREATE is a one-shot signed outboundOrder/create. An uncertain response remains uncertain; no blind retry.
- Provider ACK and identity readback are recorded. ERP shipment status and Woo tracking are unchanged until genuine shipment/tracking events.
- Channel read-only survey found DE native DHL codes DHL PAKET DOMESTIC and DHL PAKET International N, with warehouse scope not proven by catalogue; no Production order is currently approved.
- Keep actual Production CREATE disabled until one real existing ERP order is selected for controlled canary and native channel verified.

## Verified
- 73 Laravel tests / 668 assertions PASS, 94 front-end tests PASS.
- Typecheck, Vite build and 30-tab AuthenticatedLayout build verification PASS.
- This development work involved no real OMS CREATE or Production inventory mutation.
