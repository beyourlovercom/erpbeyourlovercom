# ERP global SKU gate hotfix: read-only proof provenance (2026-11-15)

Parent: PR #782 (5e0d432a). This hotfix must precede final Production feature-flag re-enablement.

## Trigger in Production / no inventory writes

After code+global feature flag was temporarily enabled, the first live XLWMS `sync(false)` generated snapshot **#25** at 2026-11-15 02:19:21 UTC. It was incorrectly marked `partial_applied` even though `sync(false)` is read-only, with `summary.applied_product_ids` of 8 and no `xlwms_inventory_lot_reconciliations` for snapshot 25.

Root cause: a legacy V2 `status=applied` snapshot #6, from before the SKU-isolation feature, was reused as if it proved a *new* SKU-level write. `persistSkuIsolatedV2` carried product IDs into a new snapshot and marked it partially applied without a write, potentially authorizing inventory. The new flag was **immediately disabled** in Production after discovery. Confirmed flag=false, provider available map empty for US-SM, no actual stock write or external OMS order creation, no general warehouse gate bypass. This is a policy/authorization correctness failure, not merely an inaccurate display.

## Hotfix safeguards

1. V2 SKU-isolation checksum schema increments 6 to **7**, making the invalid #25 checksum ineligible for new reads.
2. `sync(false)` **NEVER** assigns `applied`/`partial_applied`, `applied_at`, or SKU write authorization; no silent carryover of prior approvals.
3. Only `sync(true)` after the existing complete signed OMS read, first exact checksum authorization, per-SKU P9/identity guards and successful transactional lot reconciliation can set `summary.sku_isolation_write_proof=true` and `v2_applied=true`.
4. Per-SKU availability and OMS actual dispatch require `sku_isolation_write_proof=true` whenever source is `partial_applied`, preventing #25, future read-only mistakes and fabricated snapshots from unlocking stock.
5. Previous legacy V1/V2 accepted whole-warehouse snapshots are **not** automatically accepted as first SKU-isolated stock apply authorization. New first apply requires the exact reviewed live checksum, including for historically V2-applied warehouses.
6. Keep old behavior with global switch off. Restore global flag only after new MySQL/SQLite/30 Tab CI and controlled deployment of this hotfix. Do not manually edit snapshot #25; preserve for forensics.

## Tests

- New regression replicates legacy applied V2 plus 4 unverified SKUs: a read-only scan is blocked and never grants coverage; a write attempt with missing exact checksum is blocked; only newly approved `sync(true)` can write/authorize specific SKU.
- New regression creates a faux partial-applied snapshot with no write proof and verifies availability remains empty.
- Existing partial SKU integration test updated: after the real apply, a changed local baseline and new read-only snapshot remain blocked; a later guarded `sync(true)` creates properly verified partial state.
- Existing preflight/reservation fixtures now include explicit synthetic verified write proof to represent a *real* previously approved partial apply. No production data, external OMS calls or order writes in tests.
- PHP combined inventory/Gift/reservations: 66 tests 463 assertions PASS. Actual OMS single-order targeted cases 2/2 PASS (9 assertions).

## Production change plan

- Feature currently **off**, old conservative rules active on code 5e0d432a.
- Merged patch may be safely deployed code-only while off, preserving existing rollback symlink and backup. Reenable only after release readback with proof gate.
- Once enabled, whole-warehouse status may still display a problem, but only individually and truly applied SKU rows can be used. US-SM stock `sync(true)` still requires a reviewed exact v7 checksum and separate warehouse inventory write authorization.
