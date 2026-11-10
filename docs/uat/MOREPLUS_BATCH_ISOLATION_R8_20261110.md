# R8 MorePlus production batch: per-order quarantine / no automatic retry

## Execution status
- Owner approved long-term per-order isolation and sandbox OpenAPI investigation.
- Dedicated sandbox host https://opendev.shipout.com/api/ is TLS-reachable from US Mac mini (unauthenticated GET HTTP 401 with certificate verification passed).
- Previous encrypted/private sandbox bearer file was located on the authorized China Mac, in ~/.config/byl/shipout/sandbox_bearer.token (0600). Secret value has never been output or copied into GitHub, Production or US Mac.
- Using the China Mac, signed sandbox-only GET warehouse list and product list returned HTTP 200, result OK. Sandbox warehouse ID 1531580902677626882 exists; sandbox clientOrgId 20117.
- Signed sandbox GET stock/list returned HTTP 200, result OK for the synthetic SKU BYL-SHIPOUT-UAT-20261028-001, availableQuantity 2 before the new test.
- Signed sandbox GET queryListV2 returned HTTP 200, result OK and OMS status 4 for the existing prior sandbox orders 004/005/006.
- A fresh, sandbox-only reference BYL-SANDBOX-R8-20261110-001 was signed-GET preflighted as absent from OMS with available stock before submission. Exactly one real sandbox POST was issued to batchSubmitAsync, Mode 10, quantity 1, synthetic recipient data, and the known sandbox-only USPS GA service group. Result: HTTP 200, result OK, returned reference match and nonempty OMS ID. An exclusive 0600 one-shot marker was written on China Mac before the POST.
- CRITICAL: subsequent independent authenticated signed GET queryListV2 returned HTTP 200, result OK, exactly one OMS matching row, status=6 (cancelled). Its cancelReason was populated; sanitized keyword classification was SHIPPING_ERROR_OTHER, with 'shipping error' present but no duplicate/balance/EzeeShip marker. No sensitive provider text or recipient information was printed or persisted. The sandbox has NOT passed final OMS business acceptance.
- The exclusive sandbox one-shot marker remains; there was no second sandbox POST and no Production POST. Provider investigation is necessary before any new test order.
- Real Production batch enabled/owner-approved/canary-order flags remain OFF; no Production write or new Production ShipOut POST in this branch.

## Source changes
- Runtime fail closed: Production has no eligible candidates without explicit individually approved ERP order IDs; one-order Canary retains highest priority. Finite multi-order allowlist supports up to 200 exact IDs; malformed/empty input is rejected.
- Any earlier batch reservation, submission attempt, cancellation, unknown outcome or quarantine excludes an order from future automatic batch candidates. No automatic resubmission after POST, timeout or crash.
- One order per job. A worker crash marks only the in-progress item uncertain. If queue and owner gates remain active, it schedules the next distinct unprocessed order, never the failed one. Systemic queue failure pauses the batch.
- Signed GET-only OMS checker retains provider_cancelled / needs_review per item. Partial failure yields completed_with_exceptions, not a misleading all-success status.
- MorePlus exception list shows local quarantined batch items without provider network calls, while healthy ACK orders stay in the API-received list. Rows show a sanitized reason and no auto re-send.
- All global batch feature flags remain OFF. No new database table, tracking write, Woo state change or credential change.

## Validation
- Isolated Laravel tests cover two exact approved orders, quarantining one, continuation of a different order, worker crash, pause and queue gate, one-shot dispatch and signed GET-only cancellation.
- Full overseas warehouse workbench tests cover exception list versus normal order list and no provider network call during page rendering.
- Vue workbench tests cover partial completion and per-row quarantined display; Vite build passes.
- A dedicated GitHub CI workflow gates backend and frontend together.

## Remaining gates
- New sandbox R8 test order returned OMS status 6 (cancelled). Obtain ShipOut sandbox logs for reference BYL-SANDBOX-R8-20261110-001, Mode 10 serviceGroup 1993981773496696833, before any new sandbox POST.
- A protected test callback receiver is still needed to verify optional ShipOut async callback delivery.
- MorePlus/EzeeShip support must explain real Production duplicate-order and shipping cancellation logs, independently of sandbox. Production Mode 9 is not equivalent to the successful sandbox Mode 10 channel.

## Production rollout gates
1. Sandbox account authenticates and stock query matches test SKU.
2. Sandbox POST of one synthetic order once, async readback and duplicate/cancellation handling verified without retry.
3. Provider resolves production cancellation root causes in issue #658; sandbox success does not prove live EzeeShip configuration.
4. A brand-new never-submitted Production Canary needs separate owner approval.
5. Only after a stable noncancelled result, authorize a small exact ERP ID set in dedicated queue, observe exception list and scale gradually.
