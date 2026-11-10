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

## R10 live Production acceptance evidence added after merge
- Source release: MorePlus PR #666, merge SHA d34d433323cca53966594bbee1dfd1cc1591bdd9, staged first and activated by atomic Production release switch with previous release rollback link kept. ERP homepage/login/health responded HTTP 200.
- Read-only signed Production warehouse history check: 17 total / 17 fully examined, explicit status CLEAR and no same-content recipient/SKU overlap for selected new order. No recipient PII or raw provider payload was saved to Git.
- Exact new Canary: C42203-007, ERP order 7963, Woo processing with paid_at present, Dede-approved MorePlus US-LA, one SKU MF414A qty1, exact warehouse/provider alias/stock checks clear. Operator ID1 authorized by original V1 scope and owner gate. No previous shipout_dispatch_attempts or batch item for order_id7963 at submission time.
- 2026-11-10 LA-local ship date; one and only Production V1 POST was executed for C42203-007. API response acknowledged with result code ASYNC_ACCEPTED_QUERY_REQUIRED and provider OMS ID 2120009269894791169. Immutable ERP attempt count became exactly1; original ACK and provider ID remain in the permanent ledger.
- Immediately afterward, independent signed GET for that provider ID returned provider status3, reconciliation ORDER_VISIBLE_MANUAL_REVIEW_ONLY, shipped=false, tracking_apply=false, no known provider cancellation. A second independent signed GET showed same status3.
- ERP shipment remains pending with no Tracking or shipped_at, Woo/AfterShip untouched. DO NOT call ACK or OMS status3 final fulfillment. This shipment must not be auto-submitted a second time.
- C42141 ERP7903 latest signed OMS GET: provider status6, PROVIDER_EZEESHIP_SHIPPING_ERROR, no actual shipment; Dede manual provider fulfillment only after checking the existing OMS record. C42149 ERP7913: provider status6, PROVIDER_DUPLICATE_ORDER, confirmed duplicate, permanently excluded. Both original attempt counts exactly1.
- R10 pending final step is actual OMS noncancellation/real fulfillment evidence and Tracking handoff; Production batch flags remain OFF. Hourly read-only condition monitoring created for up to72 checks, ID Automation_049a77174cf88191b93405c8a033b2a8; must never POST, retry or write to ERP.
- GitHub sanitized evidence: https://github.com/beyourlovercom/erpbeyourlovercom/issues/658 . No secret, bearer token, raw cancel reason or recipient data is retained here.
