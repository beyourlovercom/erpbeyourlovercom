# R4-3-F: Domestic pending country-default carrier and service

Owner decision: No second batch menu, no drag-fill, no repeated per-order
carrier confirmation. The domestic pending Global Grid keeps the single
existing batch footer and right-pinned shipping actions.

## Behavior
- Kaven's successful approve/restock transaction initializes a persisted
  factory carrier/service from the existing CarrierRoutingPolicy and active
  CarrierServiceCatalog. Unknown/missing country or inactive/missing service
  fails closed: no new automatic configuration.
- Existing manually selected carrier/service remains authoritative.
  The automatic source is NOT forged as manual, and the current country
  routing policy and active service are rechecked for readiness. Other
  carriers and unsupported provider export templates are NOT silently mapped
  to 4PX. MX/CL -> Weship; AR/UA/RU -> POSTNL; TH/ID/VN -> KingTrans.
- Carrier and Service columns in pending Global Grid are direct selects
  for authorized approved-pending shipping operators. Changing either
  writes to the original carrier-service endpoint, no full-page reload or
  second batch toolbar. If carrier changes, its current master default
  service is selected. Right-pinned Tracking and explicit Ship unchanged.
- Valid automatic configurations are eligible for original 4PX/Weship
  exports and original tracking-return/quick-ship gates. Existing
  manually selected historical export rows preserve their old behavior.
  No tracking, Woo, OMS, or actual shipping write on route selection.
- One-time already-approved backlog can be preflighted by running:
    php artisan domestic:backfill-carrier-defaults
  DRY RUN by default. Only after reviewing counts, run with --apply under
  authorized controlled deployment. Each order is rechecked; manual,
  unreviewed, stockout, shipped, tracked, archived, and historical orders
  are skipped. This operation is idempotent.

## Gates
- Do not enable R4-4 or mutate a real order as an automated test.
- Verify CI, Dev browser, provider export and upload parity and independent
  admin/operator/read-only role behavior.
- Production requires immutable release + rollback and read-only nine-view
  readback. Owner visually accepts this third view before continuing.
- No schema changes. Rollback code restores prior behavior; automatic
  route values remain tagged as automatic and old code still blocks
  their shipment/export, so rollback is fail-closed.
