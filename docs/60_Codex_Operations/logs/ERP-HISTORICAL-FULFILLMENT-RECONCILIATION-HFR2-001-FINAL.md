# ERP-HISTORICAL-FULFILLMENT-RECONCILIATION-HFR2-001 — FINAL

STATUS: COMPLETE / PRODUCTION READ-ONLY / HFR-3 READY
BASELINE: `origin/main@8bd822aaee84467efb97ca5a63ed2b9b3f186fd9`
EXECUTION_PROVIDER: GPT_WEB
VERIFICATION_TIER: HIGH_RISK

## Result
The first HFR-2 source adapter now converts exact WooCommerce historical tracking metadata into the HFR-1 normalized candidate contract. It does not write Production, does not infer historical warehouse/service, and does not change existing Logistics/Finance flows.

The adapter preserves:
- exact Woo provider Order id binding;
- package tracking number;
- AfterShip provider slug plus explicit carrier alias mapping where known;
- source `ship_date`;
- exact package `line_items` bindings (`id`, `quantity`);
- duplicate/collision/ambiguous-target context;
- existing canonical Shipment context.

## Production read-only verification
Current Production runtime observed during the final scan:
`accounting-p6c-pr315-overlay-20260914-220233-fead0834`.

Exact source coverage remained stable:
- customer + shipped Orders: 5,110;
- missing canonical Shipment on root Order: 5,082;
- exact Woo remote Orders found: 5,110 / 5,110;
- gap Orders with tracking: 5,070;
- gap Orders without tracking: 12;
- multi-package gap Orders: 229;
- duplicate exact tracking groups: 16.

Final read-only scan SHA-256:
`133557bbd82e039b5a319b612667a1c9ecec2f2d74bf805decd788d70b9d0b3e`.

Adapter SHA-256:
`f291cc6512bf72549b93777a81d21f243e59fa065025dc8baea6b26f7da64787`.
## Refined HFR order matrix
| HFR class | Orders |
| --- | ---: |
| CUSTOMER_READY_BACKFILL | 0 |
| ALREADY_CANONICAL | 23 |
| CUSTOMER_REVIEW_MISSING_EVIDENCE | 5,052 |
| CUSTOMER_REVIEW_CONFLICT | 3 |
| DUPLICATE_OR_AMBIGUOUS | 32 |
| ORPHAN_EVIDENCE | 0 |

This closes exactly to 5,110 Orders.

HFR-0 had counted 26 Orders as fully canonical. HFR-2 package-level evidence refined three of those Orders (`3432`, `3483`, `3517`): each has one canonical Shipment whose tracking agrees with one Woo package, plus a second exact Woo package with no derived child Order or canonical Shipment. These are Missing Evidence at whole-order completeness level, not conflicts.

The three true conflicts remain unchanged:
- Order 1200: existing canonical Shipment tracking disagrees with Woo evidence;
- Order 2070: existing canonical Shipment tracking disagrees with Woo evidence;
- Order 3429: gap tracking collides with another ShipmentAttempt.
## Candidate/package matrix for HFR-3
Woo produces 5,354 normalized reconciliation candidates:
- ready: 0;
- review missing evidence: 5,293;
- review conflict: 3;
- review duplicate/ambiguous: 32;
- skip already canonical: 26;
- orphan: 0.

Therefore the HFR-3 source-level gate inputs are:
`ready=0`, `review=5328`, `skip=26`.

No currently available exact Production source supplies the missing historical warehouse plus carrier/service authority needed to promote any Woo gap candidate to class A.

## Integration corrections discovered by HFR-2
1. `shipped_at` remains mandatory for a new class-A backfill, but date variance alone does not invalidate an existing canonical Shipment when frozen conflict dimensions agree.
2. A second Woo package on an Order with one existing Shipment is not an `existing_shipment_conflict`; that extra package remains Missing Evidence until it has an exact canonical target.
3. Missing package target is not ambiguity by itself. Ambiguity requires multiple possible targets or duplicate mappings.
## Verification
- HFR classifier + Woo adapter: 24 tests / 74 assertions PASS.
- Actual multi-package Woo source shape verified read-only: line item bindings are `{id, quantity}`.
- PHP lint PASS.
- Pint PASS.
- Production scan used SELECT statements plus Woo GET only.
- No Production Shipment, ShipmentAttempt, Order, warehouse, carrier billing, Accounting, Finance or Woo mutation occurred.

## HFR-3 handoff
HFR-3 may now implement the guarded Shipment Backfill V2 engine, but Production apply must remain separately gated. The current Woo-only source has zero class-A candidates, so HFR-3 Production dry-run is expected to reach a zero-ready/no-op data-write gate unless a new exact warehouse/service source is introduced.

Do not rerun the original Tianyun 27-row V1 source. HFR-3 must use a new V2 manifest/gate and leave the completed V1 batch untouched.
