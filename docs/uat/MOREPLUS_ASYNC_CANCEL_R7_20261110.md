# MorePlus ShipOut asynchronous cancellation R7 — evidence and guarded rollout

Status: **ERP ACK semantics fixed in Dev; provider-side cancellation NOT solved**.
Related: Issue #658 (https://github.com/beyourlovercom/erpbeyourlovercom/issues/658), PR #656 (previously deployed).

## Verified production evidence
- Global batch flag and owner approval both OFF, exact-canary-order flag unset. No mass dispatch authorized.
- C42149 / ERP 7913: immutable POST count 1; OMS ID 2119938880028098562; signed OMS GET status 6; cancel reason classified as PROVIDER_DUPLICATE_ORDER (text indicated duplicated order).
- C42141 / ERP 7903: immutable POST count 1; OMS ID 2119935047966355458; signed OMS GET status 6; provider error class PROVIDER_EZEESHIP_SHIPPING_ERROR.
- Read-only ShipOut GET lookup for C42149-007 found one cancelled OMS order; variants 42149, C42149 and 007-1106-c42149 returned zero results. Public API did not identify the allegedly conflicting order.
- ERP same-full-address scan for both orders found no other ERP orders with identical addresses. This cannot exclude warehouse imports, store auto-sync, or other provider deduplication keys.
- Both ERP shipments pending; Tracking empty. Both remain permanently blocked against automatic resubmission.

## ERP code correction
- API ACK becomes oms_ack_pending; NOT oms_created, success or shipped.
- Batch worker stores pending_oms, never success just from a POST acknowledgement.
- Dedicated GET-only queued verification job first checks provider visibility, rechecks provisional status, and caps read checks at three. No provider POST, no automatic retry after cancellation, no Tracking/ERP/Woo writes.
- Signed provider GET statuses 6/11 become provider_cancelled. Cancellation is categorized as duplicate / EzeeShip / other without returning raw provider cancelReason strings.
- Still-visible OMS order after recheck uses oms_visible, NOT shipped. Unavailable or ambiguous readbacks become needs_review.
- No new migration; immutable one-shot attempt ledgers preserved.

## P0 provider escalation before another real POST
1. MorePlus/ShipOut: investigate duplicate-check logs for C42149-007 (OMS 2119938880028098562), identify exact conflicting order, duplicate key/source and why preflight queryListV2 missed it.
2. MorePlus/EzeeShip: investigate C42141-007 (OMS 2119935047966355458), provider trace 781.72136.17942668783862295. Check shipping API key, real-name status, account balance and auto-channel mode 9 / shipping service rules.
3. Provide confirmed correction in logs/OMS, then select a NEW never-attempted order for one controlled POST and multiple signed GET readbacks. Do not reuse C42141/C42149 or bypass duplicate rules by changing the order number.
4. Keep Production bulk switch OFF until end-to-end noncancelled receipt is verified and the dedicated queue worker release is approved.

## Regression
ShipOut feature and Gateway tests cover duplicate/EzeeShip classification, provisional visibility followed by cancellation, signed GET only, no repeat POST, no Tracking writes. Vue tests enforce pending/cancelled semantics. CI Pint / tests / build required.
