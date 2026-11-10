# MorePlus: verified Tracking completion and no-refresh inline edits

## Root cause and real Production one-time correction
- The overseas PATCH draft route saved Tracking to shipments.tracking_number, but never called markShipped. Its Inertia redirect reloaded page props/list after every field edit.
- Dede had manually imported shipments yesterday and put 10 USPS numbers in review NOTE. Those were corrected via existing audited draft service to Tracking No., clearing notes without triggering fulfillment or OMS POST.
- Signed official GET /open-api/oms/order/query individually verified the 10: nine were status4 Shipped with precisely one outbound, outboundStatus4, outboundNO, actual shippedTime and exact Tracking equal to ERP. One was status2 Awaiting Fulfillment and did not have outbound proof.
- Nine real verified orders were shipped through the pre-existing guarded fulfillment service, handing off website synchronization to the unchanged periodic Sync OS. The one status2, plus the order with no Tracking, remain pending.
- Final live queue totals after this one-time correction: review20, pending15, issued2, shipped591, exceptions5, all633. The original 19 ShipOut immutable POST attempts are unchanged. No new provider POST. Production batch remains OFF.
- The one-time completion recorded ERP confirmation time for shipped_at; the reusable automatic path below stores the source provider UTC shipped time.

## Safe next-order automatic shipping
- For US-LA only, when an authorized logistics operator enters Tracking, accept fulfillment automatically ONLY when the original immutable one-shot attempt has ACK, the Dede review is approved, and the latest signed OMS status evidence is provider_shipped.
- Independently fetch the official signed GET detail for the original provider order. Fail closed unless the warehouse, provider order ID, orderNO, status4, physical outboundStatus4, one outbound reference, shipped UTC timestamp, package list and exact actual Tracking all match.
- Reuse the original ShipmentService-based MorePlusManualFulfillment service, passing the official UTC shipped time and recording the established Sync OS handoff. Never POST to ShipOut; never mark Woo completed directly.
- Status2, status3, cancelled, ambiguous/multiple outbound, missing provider fields, mismatched Tracking, unknown carrier formats or provider errors leave the saved draft Tracking as pending. No retries of the original provider attempt.

## Inline JSON save without whole-page refresh
- Existing overseas PATCH draft route supports Accept application/json and replies with one sanitized row projection, while retaining previous redirect semantics for legacy clients.
- Vue uses Axios for the note, Tracking, carrier and service fields. It changes only the affected row, shows local save errors, disables overlapping same-row edits, and keeps scroll/filters/pagination intact.
- When backend returns auto_shipped, the order disappears from the current issued queue and local tab counts update; the shipped tab gains one. No Inertia route refresh or full-table re-query on field changes.
- All other approved/declined/manual-ship, navigation and explicit reload interactions retain their original controlled behavior.

## Validation
- Backend MorePlus/overseas/gateway/OMS/batch test suite: 1200 assertions PASS.
- Vue 59/59 PASS, Vite production build and Pint PASS.
- Regression includes true provider shipped auto-completion with original UTC time, status2 mismatch blocked without Woo/POST, human note JSON-only save, gateway PII suppression and wrong-identity block, reactive no-reload row editing.
- R14 mass ShipOut push remains OFF; supplier duplicate history P0 Issue #658 independently OPEN.
