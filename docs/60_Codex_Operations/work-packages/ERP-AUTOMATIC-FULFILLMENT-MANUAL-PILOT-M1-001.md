# ERP-AUTOMATIC-FULFILLMENT-MANUAL-PILOT-M1-001

STATUS: READ_ONLY_AUDIT_COMPLETE / PRODUCTION_WRITE_GATE_PENDING
EXECUTION_PROVIDER: GPT_WEB
VERIFICATION_TIER: HIGH_RISK
PARALLEL_SAFE: YES — documentation/read-only pilot coordination only until exact Production write gate
BASELINE: origin/main@5d7a694a1e510d3ff91c38260e84751018aec35e
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

## Current gate

`WAITING_FOR_LINE1_OWNER_UAT_AND_PRODUCTION_RUNTIME_GATE`

No Production business-data write has been performed by this Work Package.

## PROJECT POSITION

PROGRAM_ROADMAP: Real Data Onboarding complete → Fulfillment Intelligence → Manual Fulfillment Pilot → Packing/Chargeable Weight → Shipping Cost → Transport Mode → Carrier Rules → Warehouse Connectors → Automatic Dispatch → Accounting feedback
CURRENT_TRACK_PHASE_MAP: Readiness M1 COMPLETE → Manual Pilot P0 COMPLETE → P1 waiting → P2-P6 waiting → Automatic Fulfillment M2+ paused
COMPLETED_PHASES: Automatic Fulfillment Readiness M1; Manual Pilot read-only screening
CURRENT_PHASE: Manual Fulfillment Pilot gate
CURRENT_WORK_PACKAGE: ERP-AUTOMATIC-FULFILLMENT-MANUAL-PILOT-M1-001
PACKAGE_STATUS: READ_ONLY_AUDIT_COMPLETE / PRODUCTION_WRITE_GATE_PENDING
WAITING_PHASES: Inventory V2 Owner UAT; Production runtime gate; exact real-order write gate; real shipment facts; Woo outbound verification
BASELINE: origin/main@5d7a694a1e510d3ff91c38260e84751018aec35e
GITHUB_STATE: dedicated branch/worktree; delivery PR pending
SOURCE_CONFLICTS: NONE WITH EVIDENCE; Production has 0 service regions while Inventory V2 branch is not yet deployed
GLOBAL_GATE: PASS FOR READ-ONLY / STOP FOR PRODUCTION WRITE
PRODUCTION_DEPLOYMENT_STATUS: NO for this WP
NEXT_DEPENDENCY: Inventory V2 Owner Local/UAT PASS and explicit Production deployment gate
EVIDENCE_AS_OF: 2026-09-06

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
