# B2B V1 release gate: batched fresh payment references, no double loading

Date: 2026-10-23. Isolated release candidate stacked after Draft #495. No Production DB, Woo, bank, shipment or Finance mutation and no weakening of payment gates.

## Problem and safe resolution
The wait-stock controller eagerly loaded externalReferences for Orders. The independent FulfillmentPaymentGate then queried the same references with current financial status again, correctly reading a FRESH payment authority snapshot rather than trusting the cached Eloquent financials relation. Two batched reference queries violated the one-query performance contract. Removing the live payment check would be unsafe.

In this branch, remove the first redundant page preload of externalReferences only, keeping historical exclusions and all other page relations. The payment authority still fetches fresh references and financials with a single batch and evaluates ONLY this fresh financial state. Attach that verified externalReference relation to each provided Order object in memory, so subsequent allocation eligibility does not issue per-order lazy queries. No Order or financial record is written by this relation hydration.

## Critical negative coverage
Added a fail-closed test where a Woo order initially loads paid/processing state, then the ephemeral test SQLite changes the provider settlement to pending with no paid_at. The original input object remains stale, but the gate MUST see the latest pending state and deny allocation. Also assert batched source Orders receive the fresh reference relation.

## Tests and release decision
- Wait-stock ManualFulfillment: 13 tests, 169 assertions PASS, including one batched externalRefs query.
- PaymentAuthority + ManualFulfillment after revocation negative: 25 tests / 221 assertions PASS.
- Full isolated candidate before: 1,973 tests / 18,228 assertions / 0 errors / 6 failures.
- Full after: **1,974 tests / 18,239 assertions / 0 errors / 5 failures**, 6 skipped. Full CI still FAILS due to three frozen Architecture inventory failures #474 and two Logistics Owner-frozen Weship Mexico/FedEx aliases #486.
- TypeScript check and production Vite build PASS.
- No hosted CI PASS, actual authenticated PC/physical M14 Owner PASS, Finance cash attestation signoff, main merge, actual financial write activation or Production deploy claimed.

The remaining five frozen Owner/Architecture rules cannot be silently rewritten for green CI. Preserve consolidated main-targeted V1 Draft #483 and all stacked evidence. S4/S6 private Dev servers and Production unchanged; B2B Trade and Finance release write flags remain OFF. Exact-head merge/production migration/Finance activation require separate authorization.
