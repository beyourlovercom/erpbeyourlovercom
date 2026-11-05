# Tracking Sync V2 — R0 audit / controlled automatic cutover
Date: 2026-11-05 (Asia/Taipei). Scope: ERP to WooCommerce Tracking and eligible order status; reuse the existing engine only.

## Verified read-only baseline
- Current US Mac Production release mirror: inventory-ticker-pr627-80cfb975-20261104T133642Z.
- Production config: MANUAL_MODE=general, IMMEDIATE_ENABLED=false, PERIODIC_ENABLED=false. Both automatic activation baselines unset.
- SELECT-only DB query via Production-configured connection: Tracking Outbox completed=8, pending=1; Order Status Outbox completed=9; manual_sync completed=8, pending=8.
- Old Tracking Outbox ID 1: woocommerce_tracking_write_failed (2/3 attempts). Snapshot maximum IDs: Outbox=18, Shipment=1919; RECOMPUTE fresh cutover baselines before rollout, do not blindly reuse.
- Tracking Immediate Dispatch already hooks generic and domestic quick shipment/update paths. Tracking V2 already handles Woo readback, idempotent Outbox, no-overlap half-hour periodic retry, status aggregation for partial shipments, and manual operations.
- Pending manual sync records are NOT necessarily eligible for automatic push. Do not replay legacy pending or overwrite Woo/AfterShip data.
- Local isolated baseline regression: 94 tests, 884 assertions PASS; no external Woo writes.

## Minimal development delta
- Keep old Boolean IMMEDIATE_ENABLED; require independent IMMEDIATE_MODE (off | canary | general), default off.
- Canary requires exact ERP IDs in IMMEDIATE_CANARY_ORDER_IDS; empty/malformed/unknown mode fails closed.
- Do not change inbound Woo import, existing Outbox, providers, shipment truth, or legacy business rows. Production flags unchanged.

## Roadmap / engineer hours
| Stage | Scope | Hours | Gate |
| --- | --- | ---: | --- |
| R0 | Config + Outbox read-only audit | 0.5–1 | Aggregate done; case-level pending review outstanding |
| R1 | Exact-ID immediate Canary gate + regression | 1–2 | Isolated tests pass |
| R2 | Read-only classification of 8 pending manual rows and legacy Outbox ID 1 | 1–2 | No replay |
| R3 | Dev/local domestic, overseas, split, Woo/AfterShip readback UAT | 2–3 | No WWW writes |
| R4 | Separate Owner PASS to deploy code OFF, one real new-order Canary and actual Woo readback | 1–2 | Exact order authorization |
| R5 | Separate Owner PASS to enable general immediate + 30-min scheduler with freshly frozen highwater IDs; monitor at least 2 ticks | 1.5–2.5 | Idempotent / no legacy flush |
| **Total** | Existing engine reused | **7–12.5** | No Production mutation approved implicitly |

## Safety and rollback
- Do NOT use R0 max IDs as future scheduler activation baselines; capture reviewed exclusive maxima immediately before cutover.
- Do NOT mark partial, cancelled, refunded or failed Woo orders Completed. No duplicate Tracking packages, conflict overwrites, or mass pending replay.
- Canary must only run with an exact order allowlist; any unknown status or uncertain Woo write is a failure, never a green success.
- On rollback, set IMMEDIATE_ENABLED=false, IMMEDIATE_MODE=off, PERIODIC_ENABLED=false; verify schedule list and existing already-verified Woo data without deleting it.
- No production config/DB/provider writes, no READY device restart, and no Chrome restart in this package. GitHub merge is not Production approval.
