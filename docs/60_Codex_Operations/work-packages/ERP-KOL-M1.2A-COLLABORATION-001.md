# ERP-KOL-M1.2A-COLLABORATION-001

Status: OWNER UAT PASS / FINAL GATE PASS
Baseline: `main@6263c62e7888f9c6e212eb0fe7d52f9dbe5983fe`
Branch: `feature/erp-kol-m1-2a-collaboration-001`

## Scope
- formal `KolCollaboration` truth linked to one KOL and optional source Opportunity;
- explicit Opportunity -> Collaboration conversion, never implicit by stage change;
- agreed products reference canonical `products.id` and preserve SKU/name snapshots;
- cooperation post URL is the primary collaboration reference and is optional so missing URLs can be filtered for follow-up;
- quantity, cooperation type, status, amount/currency, agreed date, planned publish date, deliverables, fulfillment-required flag, notes and Owner;
- all-scope vs own-scope behavior reuses KOL authorization;
- `/kol/collaborations` is a real list with status and post-URL follow-up filters;
- Audit for create/update/conversion.

## Hard boundary
M1.2A does not create Order, Inventory, Warehouse, Shipment or Tracking truth. `fulfillment_required` is only a business requirement flag. Physical fulfillment is M1.2B and must use canonical `orders.id` with `order_kind=kol_fulfillment`.

## Final gate evidence
- focused Collaboration backend: 7 tests / 25 assertions / 0 failures;
- full KOL backend: 141 assertions / 0 failures (existing test-environment warnings retained);
- frontend Vitest: 23/23 PASS;
- TypeScript: PASS;
- production build: PASS;
- migration fresh/rehearsal plus title-nullability and legacy URL backfill compatibility: PASS;
- `git diff --check`: PASS;
- isolated Local UAT: `http://127.0.0.1:18108`;
- Owner UAT: PASS on 2026-08-28.

Delivery PR may be created. Merge remains separately gated and requires explicit Owner authorization.
