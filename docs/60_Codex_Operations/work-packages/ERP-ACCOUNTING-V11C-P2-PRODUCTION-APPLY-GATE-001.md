# ERP-ACCOUNTING-V11C-P2-PRODUCTION-APPLY-GATE-001

## Scope
Production-safe apply entry for the Owner-approved Accounting V1.1C-P2 Product Cost gate.

- Only confirmed `product_cost` from `product_cost_sources` may be written.
- Only `first_mile_cost` may accompany Product Cost to release the 11 FX-blocked DE rows.
- `customs_tax`, last-mile, KOL, payment fee, warehouse and other cost types are excluded.
- Production apply requires a single-process environment gate plus exact preflight counts.
- Approved preflight target at authorization: seen=6095, product apply=289, first-mile apply=11, FX freezes=81.
- Any preflight drift blocks the entire apply before writes.
- Existing transaction FX snapshots remain authoritative and are never revalued.
