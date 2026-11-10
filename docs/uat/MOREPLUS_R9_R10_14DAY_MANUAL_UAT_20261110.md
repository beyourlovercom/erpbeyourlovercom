# MorePlus R9/R10: content duplicate risk, Dede manual fallback and real-world gate

## Scope / source of truth
- Based on GitHub main; preserves R8 per-item isolation, one-shot ledger, ACK versus OMS reconciliation and Production batch OFF.
- No new database tables or migrations, no provider EzeeShip debugging, and no new ShipOut POST to old orders.
- C42149 (ERP 7913) confirmed duplicate: original provider cancellation and attempt are retained. No automatic or manual resubmission.
- C42141 (ERP 7903) existing Production attempt: no automatic re-POST. Dede first checks existing OMS record before using provider portal manually. A genuine outbound reference and Tracking is required to confirm ERP shipment.

## R9 14-day safety gate
- Signed ShipOut GET, bound to Production MorePlus warehouse 1265864414750314497, client 57893, *unfiltered by orderNO*.
- Scan every page (100 records per page; at most 1000) with stable total, unique provider IDs, dates, recipient details and SKU/qty; no raw PII persisted or logged.
- Include last-14-day business orderDate, and older orderDate with recent provider lastSyncTime. Older history without reliable lastSyncTime is unknown and blocked.
- Match SKU and quantity PLUS recipient name+ZIP or street+ZIP, even when orderNO differs.
- Outcome CLEAR only for full, trustworthy history; RISK for content overlap; UNKNOWN for missing history, scope, date, page, API or overflow.
- Runtime guard executes after operator preflight and before immutable attempt reservation / any POST.
- Batch worker isolates risky/unknown order independently while remaining authorized orders progress; previous actual attempts are never retried.

## Dede manual fulfillment
- Operator must validate existing real ShipOut attempt, Dede US-LA review, pending shipment, no known duplicate, unique genuine Tracking and outbound reference.
- MorePlus manual confirmation uses existing ShipmentService::markShipped. Original immutable API attempt remains untouched, audit records operator and manual outbound reference in Shipment logistics_note.
- Reuses ShipmentWebsiteSyncHandoff from main; only queues Tracking to periodic 30-minute Sync OS, never performs an immediate Woo HTTP request.
- Existing MorePlus XLSX/CSV return flow already uses the same Sync OS handoff, and remains unchanged.

## R10 real verification and gate
- R8 sandbox reference BYL-SANDBOX-R8-20261110-001: signed GET from authorized China Mac private sandbox token returned HTTP 200 / result OK / unique OMS row / status 6 / sanitized PROVIDER_SHIPPING_ERROR. No POST this round.
- No real Production Canary yet: only an entirely NEW unattempted ERP ID may pass complete 14-day history CLEAR, fresh signed stock, physical SKU mapping, Dede review, matching recipient and carrier, and owner gate.
- C42141/C42149 permanently excluded from automated submission. Production batch flags, approved ID allowlist remain OFF.

## Remaining blockers
- EzeeShip irregular failure is a Dede manual portal item, not another low-level ERP investigation.
- R10 production Canary must not proceed with UNKNOWN/at-risk data; never claim success based on API ACK alone.
- Repeat Dev + GitHub CI under latest main, then isolate a new exact Production Canary only if the real safety gate truly passes.
