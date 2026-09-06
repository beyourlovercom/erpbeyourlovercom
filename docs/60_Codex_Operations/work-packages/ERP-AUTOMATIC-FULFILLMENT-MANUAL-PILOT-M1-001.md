# ERP-AUTOMATIC-FULFILLMENT-MANUAL-PILOT-M1-001

STATUS: READ_ONLY_AUDIT_COMPLETE / PRODUCTION_WRITE_GATE_PENDING
EXECUTION_PROVIDER: GPT_WEB
VERIFICATION_TIER: HIGH_RISK
PARALLEL_SAFE: YES — documentation/read-only pilot coordination only until exact Production write gate
BASELINE: origin/main@7247c3a41b51a8b3878dc3193a1215bb59168d3b
DEPENDENCIES: Inventory V2 Automatic Allocation WP is implemented/verification-pass and Owner Local/UAT pending; Production runtime remains pre-auto-allocation.
DEPENDENCY_TYPE: EXTERNAL

## Objective

Run a bounded real-order manual fulfillment pilot before continuing Automatic Fulfillment intelligence. The pilot must expose the real human workflow and verify canonical Order → Warehouse → Reservation → Shipment Draft → Carrier/Tracking → Shipment → Woo tracking sync without inventing automation rules.

## Production baseline — 2026-09-06

- Orders: 6809.
- Operational pending Orders: 419.
- Historical materialized Orders remain excluded.
- Shipments: 0.
- Inventory Reservations: 0.
- Warehouse service regions: 0.
- Authoritative Full Snapshot warehouses: US-LA / US-NY / DE.
- Manual-fulfillment inventory screening: 278 Orders fit at least one authoritative warehouse; 140 have no single-warehouse full stock cover; 1 has unresolved Product identity.
- Product packaging profiles/current measurements in Production: 0 coverage for the selected pilot SKUs.

## Critical pilot finding

Current Production manual warehouse draft does NOT create an Inventory Reservation. Reservation is created only through the recommendation/adoption path. With service regions currently empty, recommendation is fail-closed.

Therefore the pilot MUST NOT mark a real manually-selected Order as shipped when it has no active Reservation. Doing so would create Shipment truth without the Reservation commit path that decrements canonical inventory QOH.

This is a deliberate stop condition, not a defect to bypass.

## Dependency on Inventory V2 Automatic Allocation

Active branch `feature/erp-inventory-v2-automatic-allocation-001` is currently `IMPLEMENTED / VERIFICATION_PASS / OWNER_LOCAL_UAT_PENDING`.

Its Owner-frozen routing policy is:
- DE destination may allocate only to authoritative DE warehouse.
- US remains Assisted-only until subdivision-aware routing exists.
- Other countries remain Assisted-only.
- No cross-region automatic fallback.
- No split shipment.
- Auto Apply defaults OFF.

Pilot A should begin only after the DE recommendation/reservation path has passed Owner UAT and reached an explicitly authorized Production runtime.

## Exact Pilot shortlist

### Pilot A1 — DE full closed-loop candidate
- ERP Order: `007erp-0903-c6010`
- Order ID: `6011`
- Woo reference: `40120`
- Destination: DE
- SKU: `MF414A ×1`
- Available inventory at audit: US-LA 153 / US-NY 65 / DE 5
- Expected pilot warehouse under frozen policy: DE
- Current Shipment: none
- Current Manual Sync: none
- Purpose: first stock-safe Reservation → Shipment Draft → manual carrier/tracking → mark shipped → Woo tracking outbound closed loop.

### Pilot B1 — US, warehouse uniquely US-LA
- ERP Order: `007erp-0905-c6037`
- Order ID: `6038`
- Woo reference: `40211`
- Destination: US
- SKU: `VG515A ×1`
- Available: US-LA 19 / US-NY 0 / DE 0
- Purpose: observe assisted/manual US workflow; do not mark shipped without active Reservation.

### Pilot B2 — US, warehouse uniquely US-NY
- ERP Order: `007erp-0905-c6036`
- Order ID: `6037`
- Woo reference: `40210`
- Destination: US
- SKU: `AH414 ×1`
- Available: US-LA 0 / US-NY 11 / DE 0
- Purpose: verify US-East manual choice presentation; do not mark shipped without active Reservation.

### Pilot B3 — US multi-SKU, only US-LA can cover whole Order
- ERP Order: `007erp-0901-c5961`
- Order ID: `5962`
- Woo reference: `39998`
- Destination: US
- Items: `DW01A ×1`, `VX607A ×1`
- DW01A available: US-LA 10 / US-NY 19 / DE 0
- VX607A available: US-LA 20 / US-NY 0 / DE 0
- Whole-order eligible warehouse: US-LA only
- Purpose: verify multi-SKU whole-order warehouse reasoning; do not mark shipped without active Reservation.

## Pilot phases

### P0 — Read-only candidate audit
COMPLETE.
No business-data mutation.

### P1 — DE Production runtime readiness
WAITING.
Requires Inventory V2 Automatic Allocation Owner UAT PASS and explicit Production deploy authorization for the required runtime/policy. Auto Apply remains OFF unless separately authorized.

### P2 — Exact real Order Reservation + Shipment Draft
OWNER PRODUCTION DATA-WRITE GATE.
Only exact approved Order(s). Recompute stock and eligibility immediately before mutation. Capture DB backup and before counts. Reservation + Shipment Draft must be atomic/idempotent.

### P3 — Human fulfillment facts
OWNER/OPERATOR REAL-WORLD FACT GATE.
Carrier, service, tracking number and shipped-at must come from the actual shipment. Never invent a tracking number or mark shipped merely to test software.

### P4 — Mark Shipped + Inventory commit
Only after P3 real facts exist. Verify Reservation reserved → committed, QOH decremented exactly once, quantity_reserved released exactly once, Shipment warehouse equals Reservation warehouse.

### P5 — Woo tracking outbound
Verify Manual Sync/outbox lifecycle and Woo reference `beyourlover.com`. Retry must be idempotent. Confirm remote tracking/status only after actual outbound success.

### P6 — Pilot retrospective
Record every human decision, missing data source, external lookup, override and friction point. This becomes the input to Automatic Fulfillment M2+; do not automate steps that the pilot proves are still ambiguous.

## Hard invariants

- No Historical Order may enter the pilot.
- No unresolved Product may enter the pilot.
- No fake tracking/carrier/service facts.
- No Mark Shipped without an active stock-safe Reservation for this pilot.
- No automatic dispatch/WMS action.
- QOH must not change at Reservation/Shipment Draft creation.
- QOH changes only when the Reservation commits on actual shipment.
- One Order → at most one active Reservation → one canonical Shipment.
- Production deploy and Production business-data write are separate Owner gates.
- The pilot does not expand US routing policy.

## P1 preflight — exact single-order Woo tracking outbound contract

Production read-only reconciliation confirms:

- `services.woocommerce.tracking_sync_enabled = false`; tracking is not automatically pushed by scheduler.
- Woo outbound descriptor is configured for `https://beyourlover.com` with `connection_key=beyourlover.com`.
- Current `manual_syncs = 0`; no prior pilot shipment has entered the manual sync queue.
- Mark Shipped creates/ensures the canonical Manual Sync pending record.
- Exact single-order outbound route is `POST /sync/orders/{order}/tracking-outbound`.
- Pilot must never use `tracking/all-pending`; only the exact Pilot A1 Order may be pushed.

Single-order outbound is fail-closed:

1. Order source must be WooCommerce.
2. Shipment must be `shipped`.
3. Carrier, Tracking number, and `shipped_at` must all exist.
4. Order must carry a numeric Woo reference for the configured connection.
5. Carrier must resolve through the explicit ERP carrier → AfterShip slug map.
6. ERP writes Tracking, then verifies the remote state before marking Manual Sync complete.
7. Repeating an already-completed identical outbound is idempotent.
8. If Woo already has a different Tracking value, result is `conflict`; ERP must not overwrite remote truth.
9. Transient provider failure uses the canonical outbox/retry flow, maximum 3 attempts.

Currently mapped carrier values are:

`4PX`, `DHL`, `USPS`, `UPS`, `FedEx`, `Canada Post`, `SF Express`, `Singapore Post`.

Pilot rule: the real carrier must be selected from actual shipment evidence. The pilot must not choose a carrier merely because it is mapped.

## P1 exact canary sequence — Pilot A1

Pilot A1 remains Woo `40120`, ERP `007erp-0903-c6010`, destination DE, SKU `MF414A ×1`.

After Line 1 passes Owner UAT and the Owner separately authorizes the required Production runtime/data-write step, execute only this order:

1. Fresh backup + exact preflight snapshot.
2. Confirm Order is still `pending_shipment`, has no Shipment and no active Reservation.
3. Reconfirm DE warehouse authoritative stock and exact MF414A available quantity.
4. Create/accept the canonical DE allocation through the approved Inventory V2 path.
5. Verify Reservation is `reserved`, Shipment Draft exists, and QOH is unchanged.
6. Stop until real carrier/service/tracking facts are available. No fake shipment facts.
7. Enter the real carrier/service/tracking and actual shipped time, then Mark Shipped.
8. Verify Reservation becomes `committed`, reserved quantity returns exactly, and QOH decreases exactly once.
9. Confirm one Manual Sync pending row exists for the Order.
10. From `/sync/orders`, execute the exact single-order Tracking outbound only.
11. Verify Woo remote Tracking matches ERP and Manual Sync becomes `completed`.
12. Re-run the same exact outbound once to prove idempotency; no duplicate remote Tracking/evidence may be created.
13. Reconcile Order / Shipment / Reservation / inventory / Sync Outbox / Manual Sync / audit records.

Any mismatch stops the pilot before B1/B2/B3.

## P1 Production-ready evidence — 2026-09-06

Concurrent Line 1 completed the bounded Production code-only release for PR #224. Manual Pilot did not perform the deploy or routing write. Canonical Production truth now is:

```text
release = inventory-autoalloc-v1-codeonly-20260906-080907-76deca48
auto_allocation.enabled = false
dry_run = true
scheduler_enabled = false
country_allowlist = DE
warehouse_service_regions = 1
DE -> DE warehouse priority = 1
reservations = 0
shipments = 0
```

Exact A1 Production dry-run:

```text
Order 6011 / 007erp-0903-c6010 / Woo 40120
Country = DE
State = READY_AUTO_ALLOCATE
Warehouse = DE
mutated = 0
```

Post-preview mutation check remains `Reservations=0 / Shipments=0`. MF414A at DE remains QOH 5 / reserved 0 / available 5.

The next operation is the first real Production business-data mutation: create A1 Reservation + Shipment Draft. It requires a separate explicit Owner data-write authorization and must not be inferred from `next` / `pass`.

## Current gate

`READY_FOR_PRODUCTION_DEPLOY_GATE`

No Production business-data write has been performed by this Work Package.

## PROJECT POSITION

PROGRAM_ROADMAP: Real Data Onboarding complete → Fulfillment Intelligence → Manual Fulfillment Pilot → Packing/Chargeable Weight → Shipping Cost → Transport Mode → Carrier Rules → Warehouse Connectors → Automatic Dispatch → Accounting feedback
CURRENT_TRACK_PHASE_MAP: Readiness M1 COMPLETE → Manual Pilot P0 COMPLETE → Inventory V2 Owner UAT+Merge COMPLETE → P1 Production Deploy Gate CURRENT → P2-P6 waiting → Automatic Fulfillment M2+ paused
COMPLETED_PHASES: Automatic Fulfillment Readiness M1; Manual Pilot read-only screening
CURRENT_PHASE: Manual Fulfillment Pilot gate
CURRENT_WORK_PACKAGE: ERP-AUTOMATIC-FULFILLMENT-MANUAL-PILOT-M1-001
PACKAGE_STATUS: PRODUCTION_DEPLOY_READY / BUSINESS_DATA_WRITE_NOT_AUTHORIZED
WAITING_PHASES: Production runtime deploy authorization; DE→DE routing config write authorization; exact A1 allocation write gate; real shipment facts; Woo outbound verification
BASELINE: origin/main@7247c3a41b51a8b3878dc3193a1215bb59168d3b
GITHUB_STATE: Inventory V2 PR #224 merged as 76deca4846d912009c182f4934e658643484c808; this Pilot continues in Draft PR #226
SOURCE_CONFLICTS: NONE WITH EVIDENCE; Accounting PR #222 advanced main after #224 but did not change the 11 Inventory V2 runtime paths; Production still has 0 service regions
GLOBAL_GATE: PASS FOR READ-ONLY / STOP FOR PRODUCTION WRITE
PRODUCTION_DEPLOYMENT_STATUS: NO for this WP
NEXT_DEPENDENCY: explicit Owner authorization for bounded Inventory V2 Production runtime deploy with automatic apply OFF
EVIDENCE_AS_OF: 2026-09-06

## P1 Production Release Preflight — READY

Inventory V2 PR #224 is merged. Merge commit: `76deca4846d912009c182f4934e658643484c808`. Owner Local/UAT PASS and 419 regression retest PASS are recorded in the merged package.

Production current release at this preflight is based on `5d7a694a1e510d3ff91c38260e84751018aec35e`, which is the direct parent of PR #224. No PR #224 runtime path has drifted in later `main@7247c3a41b51a8b3878dc3193a1215bb59168d3b`. Therefore the Inventory V2 runtime can be deployed as an exact bounded overlay rather than deploying unrelated concurrent Accounting/Historical changes.

Bounded runtime paths: `InventoryReservationService.php`, `AssistedFulfillmentService.php`, `AutomaticAllocationEligibilityService.php`, `AutomaticAllocationService.php`, `AutomaticAllocationCommand.php`, `LogisticsController.php`, `bootstrap/app.php`, `config/inventory_auto_allocation.php`, `resources/js/Pages/Logistics/Index.vue`, `resources/views/app.blade.php`, and `routes/console.php`. No migration is required.

Production currently has no explicit `INVENTORY_AUTO_ALLOCATION_*` environment flags. After runtime deploy, defaults remain fail-closed: `enabled=false`, `dry_run=true`, `scheduler_enabled=false`, country allowlist `DE`. Code deploy alone cannot mutate Reservations or Shipments.

A1 exact preflight remains clean: Order `6011` / Woo `40120` / ERP `007erp-0903-c6010` is `pending_shipment`, has no Shipment, no active Reservation, and MF414A has DE QOH `5`, reserved `0`, available `5`.

Production `warehouse_service_regions` remains empty. The required routing fact is exactly one row: warehouse id `3` / code `DE`, country `DE`, priority `1`. The table has a unique `(warehouse_id, country_code)` constraint. This routing-config write is separate from runtime deployment and is not authorized by the current gate. Rollback is exact deletion of that one `(3, DE)` row only if it was created by the pilot and no later approved dependency exists.

Gate separation:
1. Owner authorizes bounded runtime deploy — no business-data write.
2. Verify Production preview still fails closed before routing config.
3. Owner separately authorizes the one-row `DE→DE` routing config write.
4. Run exact A1 preview.
5. Owner separately authorizes exact A1 Reservation + Shipment Draft mutation.

## ROADMAP HOURS

CURRENT_PACKAGE_ESTIMATE: 2–5h effective execution; planning value 3.5h excluding real-world shipping wait
KNOWN_CURRENT_TRACK_REMAINING: P1-P6 only; Automatic Fulfillment M2+ deliberately paused
ARCHITECTURE_GATE_REMAINING: 0h for pilot contract
MVP1_TOTAL_ESTIMATE: historical program estimate not recomputed by this WP
MVP1_PLANNING_BASELINE: historical program baseline not recomputed
MVP1_MAXIMUM_ENVELOPE: historical program envelope not recomputed
MVP1_REMAINING: UNKNOWN WITH EVIDENCE
POST_MVP_KNOWN_INCREMENT: Automatic Fulfillment M2+ paused pending pilot findings
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; no accepted project-wide work-log ledger recomputation
ESTIMATE_CONFIDENCE: HIGH for bounded pilot operations; real-world shipment timing excluded
ESTIMATE_AS_OF: 2026-09-06
ESTIMATE_METHOD: read-only screening + bounded canary + reconciliation + retrospective

## ESTIMATED CODEX HOURS

PREFLIGHT_AND_SCOPE: 0.5–1h; planning value 0.75h
CORE_EXECUTION: 0.5–2h; planning value 1.25h
TEST_AND_VALIDATION: 0.5–1h; planning value 0.75h
REMEDIATION_BUFFER: 0.5–1h; planning value 0.75h
ESTIMATED_EFFECTIVE_HOURS: 3.5h
ESTIMATE_RANGE: 2–5h
ESTIMATE_BASIS: bounded operational pilot; excludes passive wait for physical shipment/warehouse handling
OWNER_REVIEW: INCLUDED
MERGE: INCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: INCLUDED
DEPLOYMENT: EXCLUDED

## A1 Production Canary Result — 2026-09-06

Owner explicitly authorized `A1 Reservation + Shipment Draft canary`.

Production result for Order 6011 / `007erp-0903-c6010` / Woo 40120:
- Reservation #1 created with `reservation_key=automatic-order-6011`.
- Reservation status: `reserved`; warehouse_id=3 / DE; product_id=1336 / MF414A; quantity=1.
- Shipment #1 created with status `pending`, warehouse_id=3 / DE, `warehouse_selection_source=automatic`.
- Carrier, service, tracking and shipped_at remain null.
- MF414A DE inventory: QOH remained 5; reserved changed 0 → 1; available changed 5 → 4.
- Global Production active Reservation count = 1; Shipment count = 1. No unrelated Order was allocated.
- Manual Sync remains absent; Shipment sync outbox count = 0; no Woo Tracking outbound occurred.
- Automatic allocation persistent runtime remains fail-closed: enabled=false, dry_run=true, scheduler=false.

Canary verdict: `PASS` for Reservation + Shipment Draft invariants.

Operational finding: no visible fresh pre-canary backup artifact was found in the Production backup directory. The write had already been performed by a concurrent authorized ERP lane before this pilot process reached its own backup step. Do not repeat this pattern; the next business-data write gate must verify backup evidence before mutation.

Next gate: `WAITING_REAL_CARRIER_SERVICE_TRACKING_AND_MARK_SHIPPED_OWNER_GATE`.
