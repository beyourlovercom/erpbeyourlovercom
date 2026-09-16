# ERP-HISTORICAL-FULFILLMENT-RECONCILIATION-HFR6-001

## Goal
Recover exact carrier/service evidence for HFR REVIEW rows left after HFR-5 without relaxing identity, tracking, warehouse, or conflict rules.

## Scope
- Production stays read-only through implementation, merge and fresh post-merge preflight.
- Reuse the HFR-3 classifier and Shipment-only write contract unchanged.
- Recover US-LA actual service from the same frozen HFR-5 warehouse invoices when an explicit `Ship Via` exists.
- Recover US-NY outbound service from frozen east-coast outbound invoice rows.
- Preserve exact historical service strings; do not infer from current routing.
- No Woo sync, Inventory/Accounting mutation, ShipmentAttempt creation, fuzzy matching, or provider-wide defaults.

## West actual Ship Via contract
A row may approve carrier/service only when:
- HFR candidate identity is already exact;
- invoice tracking exactly equals HFR tracking;
- evidence row is explicitly typed `actual_ship_via`;
- service comes from actual `Ship Via`, not `Request Via`;
- actual service is nonblank and is not a placeholder/instruction;
- existing carrier/service does not conflict.
`Request Via = Selected by warehouse` is not a service and does not block a separate explicit `Ship Via` value.

## East outbound invoice contract
A row may approve carrier/service only when:
- HFR candidate identity is already exact;
- candidate Woo id equals source `woo_id`;
- source order reference strictly normalizes from `C<woo>` or `C<woo>-007` to the same Woo id;
- source tracking exactly equals HFR tracking;
- evidence row is explicitly typed `east_outbound_invoice`;
- service is explicit and is not `Upload Shipping Label`;
- existing carrier/service does not conflict.

## Discovery evidence
West frozen HFR-5 invoice set:
- post-HFR5 US-LA target: 267;
- exact actual-Ship-Via matches: 262;
- absent from usable actual-Ship-Via evidence: 5;
- matched services: 255 USPS Ground Advantage, 5 USPS First Class Mail, 2 USPS Priority Mail;
- carrier conflicts in the 267 target set: 0.

East frozen outbound invoice evidence:
- exact service-backed rows capable of closing current HFR requirements: 268 in discovery;
- source identity remains exact Woo + exact tracking; upload-label placeholders remain excluded.
## Pre-implementation read-only discovery preview
- candidates: 5,354;
- newly READY after current Production state: 530;
- already applied by HFR-5: 197;
- canonical shipment agrees: 1,000;
- remaining REVIEW: 3,627;
- remaining missing approved carrier/service: 106;
- remaining missing approved warehouse: 2,966.

Discovery-only fingerprints:
- west projection SHA256: `e05168899a5c07927549797c9e2b85b36dff22ca549d86a1fbda9bdea389c257`;
- discovery source scan SHA256: `2a186c1bfd098158344592607d979c73adb6b1b09d3b175999fea75bd9bcebc9`;
- discovery source checksum: `a275ed730e6d16748fdae47d8c4017b1d8b5280827e2f92c4f25d4bcf0289920`;
- discovery plan checksum: `0719a439d817d0db16a9fbf7d16ba51c778f48dc223247263e0f6f4d079b2db5`.

These discovery fingerprints are not valid for Production apply.

## Write boundary
After merge, regenerate candidates from current Production state, reapply only canonical HFR-6 evidence adapters, and run a fresh read-only HFR-3 preview.
Any resulting READY set requires a new exact Owner Production Data Write Gate before `--apply`.
