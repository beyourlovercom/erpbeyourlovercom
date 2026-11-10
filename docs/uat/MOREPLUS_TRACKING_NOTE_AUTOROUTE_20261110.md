# MorePlus: USPS Tracking in the remarks field, fixed and prevented

## Real Production read-only preflight and correction
- User reported Dede uploaded existing warehouse shipments manually and filled tracking values into the review NOTE instead of Tracking No.
- Only US-LA issued queue was scanned. 11 issued orders; 10 reviews contained exact unique USPS-formatted 22-digit values starting 92/93/94 with no existing ERP Tracking, 1 review had no tracking note. Nine of those ten had last signed ShipOut status 4 (provider_shipped); one had status 2 (warehouse_awaiting).
- The existing auditable OverseaWarehouseReviewService::updateDraft was used to atomically move the 10 validated values into shipments.tracking_number and clear only those review notes. Verification per order confirmed the new Tracking persisted, note cleared, ERP shipment status remained pending. The other 1 order was not changed.
- This one-time data correction did NOT call ShipOut, did NOT set ERP shipped, did NOT send Woo/AfterShip, and did NOT enable Production batch.
- The 22-digit identifiers are customer shipment data; never put them into GitHub, logs, tests, or incident summaries. Use order IDs and last four digits in administrative evidence only.

## Permanent minimal UI and backend repair
- On MorePlus US-LA ONLY, if a note entered through the existing row editor exactly matches a USPS 22-digit number starting 92/93/94, the backend routes it to Tracking No. and clears the note in the same service transaction.
- The editor updates the two cells after successful save, and the note placeholder expressly says to place tracking in the Tracking column.
- Other warehouses and human remarks retain original semantics.
- Guard against overwriting an existing different Tracking; prevent a Tracking number already used by another order. Require logistics execution permission, including when user tries to bypass the UI by directly calling PATCH; reviewers cannot use the note to write Tracking.
- Never set shipped, write a website status, or send an OMS POST when auto-routing a note. Real outbound proof and existing manual fulfill requirements remain mandatory.

## Dev QA
- Backend workbench, batch, OMS and manual fulfillment tests: 1123 assertions PASS.
- Vue overseas page: 58/58 PASS.
- Production Vite build, Pint PASS.
- New tests cover automatic routing, other warehouse/human note preserved, duplicate number and overwrite rejected, reviewer privilege denied, and no provider HTTP.
- Independent R14 bulk push flags remain OFF.
