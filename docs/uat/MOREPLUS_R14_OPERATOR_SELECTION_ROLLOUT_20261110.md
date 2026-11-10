# MorePlus R14 operator-initiated batch rollout (2026-11-10)

## Real Production evidence, not simulated success
- Source main b67829ddea6331481851986c3fa79c1abb17224b at the start of this work.
- First new-order Canary ERP #7957: one provider POST and ACK, then OMS status 6 Cancelled / PROVIDER_DUPLICATE_ORDER, quarantined; no automatic retransmission or ERP shipped/Tracking/Woo mutation.
- Second finite two-order batch c68921ab-f00e-47e5-ad08-847d95a7cc29: ERP #7795 and #7972 passed live signed stock and 14-day history checks (20 visible rows), posted exactly once each, both signed OMS GET code 3 and independent second confirmation at least 120 seconds later. Batch completed, zero errors. Not physically shipped and no website synchronization.
- Total immutable attempts after batch: 22. The temporary writer flags were restored OFF and all delayed GET-only readbacks completed. Dedicated queue empty.
- Remaining approved/pending preflight: 11 orders had unavailable physical warehouse stock and 1 had an older ID than production cutover. These are not known physically sendable.
- Provider hidden 14-day full-content duplicate rule may still cancel an apparently clear signed history check. Isolate, never forge content or resend an attempted order.

## R14 change: one-click selected/all batch without engineer editing IDs
- New env SHIPOUT_PRODUCTION_V1_BATCH_OPERATOR_SELECTION_ENABLED defaults false.
- Dynamic mode requires Canary ID=0, static approved order IDs empty, minimum order cutoff >0, Production environment. Existing exact Canary/static ID allowlist has priority and cannot be bypassed.
- Existing exact three named ShipOut operator IDs, logistics permissions and both owner Production batch approvals remain mandatory.
- UI selected rows or empty selection with explicit all-pending confirmation; preview SHA-256 binds selected IDs, candidates and scanned count, revalidated by start. Max 200 per batch.
- Candidate local gates: reviewed US-LA, USPS, US destination, eligible paid order, no operational stockout, tracking empty, no prior immutable attempt or batch-quarantined item, new order above cutoff. Each worker performs fresh provider signed physical stock and 14-day content-risk GET again immediately before its only POST.
- One batch item failing/uncertain/provider cancelled ends in existing six-view 异常订单. The next queued distinct item proceeds independently after outbound pacing. Once an ERP order has any immutable POST, no automatic repost, irrespective of ACK, OMS cancellation or status query oscillation.
- R14 supplementary fix: if a later signed GET changes a previously completed OMS-visible item to cancelled/unverified, also update its historical batch item and completed batch status to completed_partial; raw signed ACK, shipment and tracking stay unchanged.
- No background or scheduled new-order POST: a permitted staff member must initiate and confirm each batch; the existing separate periodic OMS GET scheduler continues.

## Gate/rollback
- Writer flags SHIPOUT_PRODUCTION_V1_BATCH_ENABLED and SHIPOUT_PRODUCTION_V1_BATCH_OWNER_APPROVED are independently killable. Setting either OFF stops any future queued provider POST. Never restart READY devices or Chrome.
- For rollback set batch enabled, batch owner approved and new operator selection enabled all to false; clear Laravel config, restart only dedicated ShipOut queue worker. Retain all existing immutable attempts and readbacks.

## Dev evidence
- Affected ShipOut/Overseas backend: 1566 assertions passed; Vue 59/59; Vite production build and Pint. Includes Production admin selected workflow, explicit default OFF, both static modes, repeated submission prevention, late provider cancellation and no provider POST during Dev.
