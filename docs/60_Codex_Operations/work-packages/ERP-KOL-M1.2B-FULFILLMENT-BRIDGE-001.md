# ERP-KOL-M1.2B-FULFILLMENT-BRIDGE-001

Status: FINAL GATE PASS / OWNER UAT PASS
Baseline: `main@4e2bfb62efb62e5c92893b1e6bbc05c6971c48e3`
Branch: `feature/erp-kol-m1-2b-fulfillment-bridge-001`

## Scope
- Explicitly bridge a formal KOL Collaboration that requires physical fulfillment to one canonical Order.
- Persist only `kol_collaborations.order_id` as the KOL-side link.
- Created Order is always `order_kind=kol_fulfillment` and `source_type=kol`.
- Copy canonical Product IDs / quantities from Collaboration products into canonical Order Items.
- Copy the KOL shipping address into the canonical Order address at creation time.
- Project existing Order / Shipment / Tracking truth back into KOL Detail/Edit.
- Require both KOL operate and Order create permissions.
- Creation is explicit and idempotent: one Collaboration can bind at most one Order.

## Hard boundary
KOL does not own Inventory, Warehouse, Shipment, Tracking, Reservation, allocation, shipping-cost, carrier-rule or WMS truth. Those remain in canonical Order / Inventory / Logistics / Sync modules.

## Final gate evidence
- focused bridge backend: 4 tests / 19 assertions / 0 failures;
- full KOL backend: 160 assertions / 0 failures;
- frontend Vitest: 23/23 PASS;
- TypeScript: PASS;
- production build: PASS;
- migration fresh -> rollback-last -> reapply on disposable SQLite: PASS;
- `git diff --check`: PASS;
- isolated Local UAT: `http://127.0.0.1:18109`;
- Owner UAT: PASS on 2026-08-28.

## UAT / migration fixes found before delivery
- Corrected 18109 to use the isolated M1.2B SQLite database instead of a stale `/tmp` UAT database; this removed the false 403 during Owner UAT.
- Corrected migration rollback order to drop the `order_id` unique index before dropping the constrained column.

Merge remains separately gated by explicit Owner authorization.
